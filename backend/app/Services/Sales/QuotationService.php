<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\NumberService;
use App\Services\SettingsService;
use App\Support\Branches;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** FR-708: a priced offer that reserves nothing; converting it checks stock again and hands the cart to the POS. */
class QuotationService
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly NumberService $numbers,
        private readonly SettingsService $settings,
        private readonly InvoicePdfService $pdf,
    ) {}

    /** @param  array<string, mixed>  $in  {customer_id?, vehicle?, lines: [{variant_id, qty, unit_price, discount_type, discount_value}], delivery_charge?, valid_until?, note?} */
    public function create(User $user, array $in): Quotation
    {
        $customer = Customer::query()->find((int) ($in['customer_id'] ?? 0)) ?? Customer::query()->where('is_walk_in', true)->firstOrFail();
        $defaultRate = (string) (TaxRate::query()->where('code', $this->settings->get('default_tax_rate_code'))->value('rate_percent') ?? '0');
        $variants = ProductVariant::query()->with(['product.taxRate', 'taxRate'])->whereIn('id', array_column($in['lines'], 'variant_id'))->get()->keyBy('id');
        $rows = [];
        foreach (array_values($in['lines']) as $i => $l) {
            $v = $variants[(int) $l['variant_id']] ?? null;
            if ($v === null || ! $v->is_active) {
                throw new ApiException('VARIANT_UNAVAILABLE', 422, '', ["lines.$i.variant_id" => ['errors.VARIANT_UNAVAILABLE']]);
            }
            $rows[] = ['variant' => $v, 'qty' => (string) $l['qty'], 'unit_price' => (string) ($l['unit_price'] ?? $v->retail_price), 'discount_type' => $l['discount_type'] ?? 'none', 'discount_value' => (string) ($l['discount_value'] ?? '0'),
                'tax_rate' => (string) ($v->taxRate?->rate_percent ?? $v->product->taxRate?->rate_percent ?? $defaultRate)];
        }
        $calc = SalePricing::compute(array_map(fn ($r) => array_diff_key($r, ['variant' => 1]), $rows), 'none', '0', (string) ($in['delivery_charge'] ?? '0'), (string) $this->settings->get('vat_mode'), (string) $this->settings->get('rounding_mode'));

        $quotation = DB::transaction(function () use ($in, $customer, $rows, $calc): Quotation {
            $branch = Branches::currentId();
            $q = Quotation::query()->create([
                'branch_id' => $branch, 'quote_no' => $this->numbers->next('quotation', $branch), 'customer_id' => $customer->id, 'customer_snapshot' => SaleSnapshots::customer($customer),
                'vehicle_snapshot' => SaleSnapshots::vehicle($in['vehicle'] ?? null, $customer), 'status' => 'draft', 'quote_date' => Branches::today(),
                'valid_until' => $in['valid_until'] ?? now('Asia/Dhaka')->addDays(self::VALID_DAYS)->toDateString(), 'subtotal' => $this->m($calc['subtotal']),
                'discount_total' => $this->m($calc['line_discount_total']), 'tax_total' => $this->m($calc['tax_total']), 'delivery_charge' => $this->m($calc['delivery_charge']),
                'grand_total' => $this->m($calc['grand_total']), 'note' => $in['note'] ?? null,
            ]);
            foreach ($rows as $i => $r) {
                $c = $calc['lines'][$i];
                $q->items()->create([
                    'variant_id' => $r['variant']->id, 'description_snapshot' => trim($r['variant']->product->name_en.' · '.($r['variant']->variant_name ?? $r['variant']->sku)),
                    'qty' => Dec::fmt($c['qty'], Dec::QTY), 'unit_price' => $this->m($c['unit_price']), 'discount_type' => $r['discount_type'], 'discount_value' => $this->m(Dec::parse($r['discount_value'], Dec::MONEY)),
                    'tax_rate_percent' => $r['tax_rate'], 'line_total' => $this->m($c['line_total']),
                ]);
            }

            return $q;
        });
        try {
            $this->pdf->ensureQuotation($quotation);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Quotation PDF failed', ['quotation_id' => $quotation->id, 'error' => $e->getMessage()]);
        }

        return $quotation->refresh();
    }

    public function setStatus(Quotation $q, string $status): Quotation
    {
        $allowed = ['draft' => ['sent', 'accepted', 'cancelled'], 'sent' => ['accepted', 'cancelled'], 'accepted' => ['cancelled']];
        if (! in_array($status, $allowed[$q->status] ?? [], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
        $q->update(['status' => $status]);

        return $q->refresh();
    }

    /**
     * Turns a quotation into a POS cart. Nothing is held; stock is checked now and again at checkout.
     *
     * @return array{cart: array<string, mixed>, shortages: array<int, array<string, mixed>>}
     */
    public function convert(Quotation $q, int $warehouseId): array
    {
        if (! in_array($q->status, ['draft', 'sent', 'accepted'], true)) {
            throw new ApiException('INVALID_STATE', 409);
        }
        if ($q->valid_until !== null && $q->valid_until->toDateString() < Branches::today()) {
            $q->update(['status' => 'expired']);
            throw new ApiException('QUOTATION_EXPIRED', 422);
        }
        $lines = [];
        $short = [];
        foreach ($q->items as $item) {
            $v = ProductVariant::query()->find($item->variant_id);
            $have = Dec::parse((string) DB::table('stock_levels')->where(['variant_id' => $item->variant_id, 'warehouse_id' => $warehouseId])->whereNull('deleted_at')->selectRaw('COALESCE(SUM(qty_on_hand - qty_reserved), 0) as a')->value('a'), Dec::QTY);
            if ($v !== null && ! $v->is_bundle && $have < Dec::parse((string) $item->qty, Dec::QTY)) {
                $short[] = ['variant_id' => $item->variant_id, 'sku' => $v->sku, 'available' => Dec::fmt(max($have, 0), Dec::QTY), 'requested' => $item->qty];
            }
            $lines[] = ['variant_id' => $item->variant_id, 'qty' => $item->qty, 'unit_price' => $item->unit_price, 'discount_type' => $item->discount_type, 'discount_value' => $item->discount_value];
        }

        return ['cart' => ['quotation_id' => $q->id, 'customer_id' => $q->customer_id, 'vehicle' => $q->vehicle_snapshot, 'lines' => $lines, 'delivery_charge' => $q->delivery_charge], 'shortages' => $short];
    }

    private function m(int $paisa): string
    {
        return Dec::fmt($paisa, Dec::MONEY);
    }
}
