<?php

namespace App\Services\Sales;

use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\LookupValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * FR-902: what an invoice remembers. Taken once at sale time from the live records, then never re-read, so later edits to the
 * company, the customer or the catalogue cannot change an old invoice (BR-050). Keys follow spec invoiceSpec.snapshotSchema.
 */
class SaleSnapshots
{
    /** @return array<string, mixed> */
    public static function company(CompanyProfile $c): array
    {
        return [
            'legal_name' => $c->legal_name, 'trade_name' => $c->trade_name, 'tagline' => $c->tagline ?: 'Trust. Growth. Excellence.',
            'logo_url' => 'assets/brand/orbin-logo.jpg', 'address_line1' => $c->address_line1, 'address_line2' => $c->address_line2,
            'city' => $c->city, 'district' => $c->district, 'postcode' => $c->postcode, 'country' => $c->country,
            'phones' => array_values(array_filter($c->phones ?? [], fn ($p) => trim((string) $p) !== '')), 'whatsapp' => $c->whatsapp, 'email' => $c->email, 'website' => $c->website,
            'bin_no' => $c->bin_no, 'tin_no' => $c->tin_no, 'trade_license_no' => $c->trade_license_no,
            'bank_name' => $c->bank_name, 'bank_branch' => $c->bank_branch, 'bank_account_name' => $c->bank_account_name, 'bank_account_no' => $c->bank_account_no,
            'signature_url' => $c->signature_url, 'invoice_terms' => $c->invoice_terms, 'return_policy_text' => $c->return_policy_text,
            'warranty_policy_text' => $c->warranty_policy_text, 'footer_note' => $c->footer_note,
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $c): array
    {
        return [
            'code' => $c->code, 'name' => $c->name, 'company_name' => $c->company_name, 'phones' => array_values($c->phones ?? []), 'email' => $c->email,
            'address_line1' => $c->address_line1, 'address_line2' => $c->address_line2, 'area' => $c->area, 'district' => $c->district, 'postcode' => $c->postcode,
            'bin_no' => $c->bin_no, 'nid_no' => $c->nid_no,
            'customer_type' => $c->customer_type_id ? LookupValue::query()->whereKey($c->customer_type_id)->value('label_en') : null, 'is_walk_in' => (bool) $c->is_walk_in,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input  {customer_vehicle_id?, reg_no?, make?, model?, year?, color?}
     * @return array<string, mixed>|null
     */
    public static function vehicle(?array $input, Customer $customer): ?array
    {
        if (! $input) {
            return null;
        }
        if (! empty($input['customer_vehicle_id'])) {
            $v = CustomerVehicle::query()->with(['make', 'model'])->where(['id' => (int) $input['customer_vehicle_id'], 'customer_id' => $customer->id])->first();
            if ($v !== null) {
                return ['reg_no' => $v->reg_no, 'make' => $v->make?->name, 'model' => $v->model?->name, 'year' => $v->year, 'color' => $v->color];
            }
        }
        $out = [];
        foreach (['reg_no', 'make', 'model', 'year', 'color'] as $k) {
            $val = trim((string) ($input[$k] ?? ''));
            $out[$k] = $val === '' ? null : $val;
        }

        return array_filter($out, fn ($v) => $v !== null) === [] ? null : $out;
    }

    /** @return array<string, mixed> */
    public static function spec(ProductVariant $v): array
    {
        $label = fn (?int $id) => $id ? LookupValue::query()->whereKey($id)->value('label_en') : null;
        $product = $v->product;

        return [
            'position' => $v->position, 'size_label' => $v->size_label,
            'size_mm' => $v->size_width_mm !== null || $v->size_height_mm !== null ? ['w' => $v->size_width_mm, 'h' => $v->size_height_mm] : null,
            'glass_type' => $label($v->glass_type_id), 'tint' => $label($v->tint_id), 'color_finish' => $label($v->color_finish_id),
            'adjustment' => $v->adjustment, 'features' => array_values(array_filter(ProductVariant::FEATURE_FLAGS, fn ($f) => (bool) $v->{$f})),
            'sku' => $v->sku, 'oem_numbers' => DB::table('oem_cross_references')->where('variant_id', $v->id)->whereNull('deleted_at')->orderBy('id')->pluck('number')->all(),
            'brand' => $product->brand?->name, 'quality_grade' => $product->qualityGrade?->label_en, 'origin' => $label($product->origin_country_id),
        ];
    }
}
