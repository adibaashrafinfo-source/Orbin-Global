<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Services\Sales\CustomerService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\WarrantyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** FR-601 to FR-605, FR-708, FR-717. */
class CustomerAndQuotationTest extends SalesTestCase
{
    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return 'NO_ERROR';
    }

    public function test_customers_get_gap_free_codes_and_an_opening_balance_that_is_a_ledger_entry(): void
    {
        $a = $this->customer(['opening_balance' => '2500.00']);
        $b = $this->customer();
        $this->assertSame('CUS-000001', $a->code);
        $this->assertSame('CUS-000002', $b->code);
        $this->assertSame('2500.00', app(CustomerService::class)->balance($a->id));
        $this->assertSame('0.00', app(CustomerService::class)->balance($b->id));
        $this->assertSame(1, DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $a->id, 'entry_type' => 'opening'])->count());
    }

    public function test_the_primary_phone_is_kept_as_digits_and_search_text_is_maintained(): void
    {
        $c = $this->customer(['name' => 'Karim Motors', 'company_name' => 'KM Garage', 'phones' => ['01712345678', '01812345678']]);
        $this->assertSame('01712345678', $c->phone_primary);
        $this->assertStringContainsString('karim motors', $c->search_text);
        $this->assertStringContainsString('km garage', $c->search_text);
        $this->assertStringContainsString('01812345678', $c->search_text);
    }

    public function test_the_same_phone_warns_with_a_link_to_the_existing_customer_until_acknowledged(): void
    {
        $first = $this->customer(['phones' => ['01711112222']]);
        try {
            app(CustomerService::class)->create(['name' => 'Another', 'phones' => ['01711112222']]);
            $this->fail('expected a duplicate warning');
        } catch (ApiException $e) {
            $this->assertSame(['CUSTOMER_PHONE_EXISTS', 409, $first->id], [$e->errorCode, $e->status, $e->context['existing']['id']]);
        }
        $second = app(CustomerService::class)->create(['name' => 'Another', 'phones' => ['01711112222']], true);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_there_is_exactly_one_walk_in_who_cannot_be_edited_or_deleted(): void
    {
        $w = $this->walkIn();
        $this->assertSame(1, Customer::query()->where('is_walk_in', true)->count());
        $this->assertSame('WALKIN_PROTECTED', $this->code(fn () => app(CustomerService::class)->update($w, ['name' => 'X'])));
        $this->assertSame('WALKIN_PROTECTED', $this->code(fn () => app(CustomerService::class)->delete($w)));
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customers')->insert(['code' => 'CUS-DUP', 'name' => 'Second walk-in', 'phones' => '[]', 'walkin_key' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_customer_with_sales_cannot_be_deleted_and_vehicles_are_unique_per_customer(): void
    {
        [$v] = $this->stocked(1, '5');
        $c = $this->customer();
        $this->checkout($this->payload([$v => '1'], ['customer_id' => $c->id]));
        $this->assertSame('IN_USE', $this->code(fn () => app(CustomerService::class)->delete($c)));
        $svc = app(CustomerService::class);
        $svc->saveVehicle($c, ['reg_no' => ' dhaka metro-ga 12-3456 ', 'year' => 2015]);
        $this->assertSame('VEHICLE_DUPLICATE', $this->code(fn () => $svc->saveVehicle($c, ['reg_no' => 'DHAKA METRO-GA 12-3456'])));
        $this->assertSame(1, $c->vehicles()->count());
    }

    public function test_a_quotation_prices_the_cart_reserves_nothing_and_has_a_pdf(): void
    {
        [$a, $b] = $this->stocked(2, '10');
        $this->actingAsUser($this->cashier);
        $q = app(QuotationService::class)->create($this->cashier, ['lines' => [['variant_id' => $a, 'qty' => '2'], ['variant_id' => $b, 'qty' => '1', 'discount_type' => 'percent', 'discount_value' => '10']], 'delivery_charge' => '100.00']);
        // 2 × 1000 + 1 × 1000 less 10% (100) + 100 delivery = 3000.
        $this->assertSame(['draft', '3000.00', '100.00', '100.00', '3000.00'], [$q->status, $q->subtotal, $q->discount_total, $q->delivery_charge, $q->grand_total]);
        $this->assertStringStartsWith('ORB-QT-', $q->quote_no);
        $this->assertSame('10.000', $this->onHand($a), 'a quotation holds no stock');
        $this->assertNotNull($q->pdf_path);
        $this->assertSame($q->quote_date->addDays(7)->toDateString(), $q->valid_until->toDateString());
        $this->assertCount(2, $q->items);
    }

    public function test_converting_a_quotation_checks_stock_and_checkout_marks_it_converted(): void
    {
        [$v] = $this->stocked(1, '3');
        $this->actingAsUser($this->cashier);
        $svc = app(QuotationService::class);
        $q = $svc->create($this->cashier, ['lines' => [['variant_id' => $v, 'qty' => '5']]]);
        $out = $svc->convert($q->refresh(), $this->showroom);
        $this->assertSame([5, 1], [(int) $out['cart']['lines'][0]['qty'], count($out['shortages'])]);
        $this->assertSame(['3.000', '0.00'], [$out['items'][$v]['variant']['available'], $out['items'][$v]['variant']['tax_rate']], 'each line comes with what the POS needs to rebuild it');
        $this->assertSame(['3.000', '5.000'], [$out['shortages'][0]['available'], $out['shortages'][0]['requested']]);

        $q2 = $svc->create($this->cashier, ['lines' => [['variant_id' => $v, 'qty' => '2']]]);
        $this->assertSame([], $svc->convert($q2->refresh(), $this->showroom)['shortages']);
        $sale = $this->checkout($this->payload([$v => '2'], ['quotation_id' => $q2->id]));
        $this->assertSame(['converted', $sale->id], [$q2->refresh()->status, $q2->converted_sale_id]);
        $this->assertSame('INVALID_STATE', $this->code(fn () => $svc->convert($q2->refresh(), $this->showroom)));
    }

    public function test_an_expired_quotation_cannot_be_converted_and_status_moves_only_forward(): void
    {
        [$v] = $this->stocked(1, '3');
        $this->actingAsUser($this->cashier);
        $svc = app(QuotationService::class);
        $q = $svc->create($this->cashier, ['lines' => [['variant_id' => $v, 'qty' => '1']], 'valid_until' => now('Asia/Dhaka')->subDay()->toDateString()]);
        $this->assertSame('QUOTATION_EXPIRED', $this->code(fn () => $svc->convert($q->refresh(), $this->showroom)));
        $this->assertSame('expired', $q->refresh()->status);
        $fresh = $svc->create($this->cashier, ['lines' => [['variant_id' => $v, 'qty' => '1']]]);
        $this->assertSame('sent', $svc->setStatus($fresh, 'sent')->status);
        $this->assertSame('INVALID_STATE', $this->code(fn () => $svc->setStatus($fresh->refresh(), 'draft')));
    }

    public function test_a_warranty_claim_needs_a_line_still_in_warranty_and_a_replacement_leaves_stock(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '1']));
        $item = $sale->items()->first();
        $this->actingAsUser($this->cashier);
        $svc = app(WarrantyService::class);
        $claim = $svc->create($this->cashier, ['sale_item_id' => $item->id, 'issue' => 'glass cracked, not fitted']);
        $this->assertSame(['open', $sale->customer_id], [$claim->status, $claim->customer_id]);
        $this->assertStringStartsWith('ORB-WC-', $claim->claim_no);
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $done = $svc->update($manager, $claim, ['status' => 'replaced', 'resolution' => 'swapped for a new one']);
        $this->assertSame(['replaced', $manager->id], [$done->status, $done->resolved_by]);
        $this->assertSame('3.000', $this->onHand($v), 'five on the shelf, one sold, one given as the replacement');
        $this->assertSame('INVALID_STATE', $this->code(fn () => $svc->update($manager, $done->refresh(), ['status' => 'rejected'])));

        DB::table('sale_items')->where('id', $item->id)->update(['warranty_until' => now('Asia/Dhaka')->subDay()->toDateString()]);
        $this->assertSame('WARRANTY_EXPIRED', $this->code(fn () => $svc->create($this->cashier, ['sale_item_id' => $item->id, 'issue' => 'late'])));
    }

    public function test_a_sale_without_warranty_cannot_be_claimed(): void
    {
        [$v] = $this->stocked(1, '5');
        DB::table('products')->where('id', ProductVariant::query()->find($v)->product_id)->update(['warranty_months' => 0]);
        $sale = $this->checkout($this->payload([$v => '1']));
        $this->assertSame('WARRANTY_EXPIRED', $this->code(fn () => app(WarrantyService::class)->create($this->cashier, ['sale_item_id' => $sale->items()->first()->id, 'issue' => 'x'])));
    }
}
