<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\SettingsService;

/** The HTTP contract of the POS: headers, error shape, hidden cost, downloads and the public verify page. */
class SalesApiTest extends SalesTestCase
{
    /** @param  array<string, mixed>  $payload */
    private function checkoutHttp(array $payload, string $key = 'key-1')
    {
        unset($payload['idempotency_key']);

        return $this->postJson('/api/v1/sales/checkout', $payload, ['Idempotency-Key' => $key]);
    }

    public function test_checkout_needs_the_idempotency_key_header(): void
    {
        [$v] = $this->stocked(1, '5');
        $this->actingAsUser($this->cashier);
        $payload = $this->payload([$v => '1']);
        unset($payload['idempotency_key']);
        $this->postJson('/api/v1/sales/checkout', $payload)->assertStatus(422)->assertJsonPath('code', 'VALIDATION')->assertJsonPath('fields.idempotency_key.0', 'errors.REQUIRED');
    }

    public function test_checkout_returns_the_invoice_with_download_links_and_double_posts_are_one_sale(): void
    {
        [$v] = $this->stocked(1, '5');
        $this->actingAsUser($this->cashier);
        $first = $this->checkoutHttp($this->payload([$v => '2']), 'same-click')->assertCreated();
        $data = $first->json('data');
        $this->assertStringStartsWith('ORB-INV-', $data['invoice_no']);
        $this->assertSame(['/api/v1/sales/'.$data['id'].'/pdf', 'Invoice_'.$data['invoice_no'].'_Walk-in-Customer.pdf', true], [$data['pdf_url'], $data['download_name'], $data['has_pdf']]);
        $this->assertSame('2000.00', $data['grand_total']);
        $this->assertCount(1, $data['items']);
        $this->assertCount(1, $data['payments']);
        $again = $this->checkoutHttp($this->payload([$v => '2']), 'same-click')->assertCreated();
        $this->assertSame($data['id'], $again->json('data.id'));
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_domain_errors_use_the_uniform_shape_and_keep_nothing(): void
    {
        [$v] = $this->stocked(1, '1');
        $this->actingAsUser($this->cashier);
        $this->checkoutHttp($this->payload([$v => '1'], ['client_expected_grand_total' => '1.00']))->assertStatus(422)->assertJsonPath('code', 'TOTAL_MISMATCH')->assertJsonPath('message', 'errors.TOTAL_MISMATCH')->assertJsonStructure(['code', 'message', 'request_id']);
        $this->checkoutHttp($this->payload([$v => '3']), 'k2')->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('context.available', '1.000');
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_validation_failures_name_the_field(): void
    {
        $this->actingAsUser($this->cashier);
        $this->postJson('/api/v1/sales/checkout', ['lines' => [], 'customer_id' => 999999], ['Idempotency-Key' => 'k'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION')->assertJsonStructure(['fields' => ['lines', 'customer_id', 'warehouse_id', 'client_expected_grand_total']]);
    }

    public function test_cost_is_in_the_invoice_only_for_those_with_the_cost_permission(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '1']));
        $this->actingAsUser($this->cashier);
        $this->assertArrayNotHasKey('unit_cost', $this->getJson("/api/v1/sales/{$sale->id}")->assertOk()->json('data.items.0'));
        $this->actingAsRole('manager');
        $this->assertSame('600.0000', $this->getJson("/api/v1/sales/{$sale->id}")->assertOk()->json('data.items.0.unit_cost'));
    }

    public function test_the_invoice_downloads_as_a_named_pdf_and_a_reprint_is_counted(): void
    {
        [$v] = $this->stocked(1, '5');
        $c = $this->customer(['name' => 'Karim Motors & Sons']);
        $sale = $this->checkout($this->payload([$v => '1'], ['customer_id' => $c->id]));
        $this->actingAsUser($this->cashier);
        $res = $this->get("/api/v1/sales/{$sale->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Invoice_'.$sale->invoice_no.'_Karim-Motors-Sons.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertSame('%PDF', substr($res->getContent(), 0, 4));
        $this->assertSame(0, $sale->refresh()->reprint_count);
        $this->get("/api/v1/sales/{$sale->id}/pdf?reprint=1&inline=1")->assertOk()->assertHeader('Content-Disposition', 'inline; filename="Invoice_'.$sale->invoice_no.'_Karim-Motors-Sons.pdf"');
        $this->assertSame(1, $sale->refresh()->reprint_count);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale_reprint', 'entity_id' => $sale->id]);
        $this->assertSame('%PDF', substr($this->get("/api/v1/sales/{$sale->id}/receipt")->assertOk()->getContent(), 0, 4));
    }

    public function test_the_public_verify_page_shows_the_bare_facts_and_no_personal_data(): void
    {
        [$v] = $this->stocked(1, '5');
        $c = $this->customer(['name' => 'Secret Person', 'phones' => ['01799887766']]);
        $sale = $this->checkout($this->payload([$v => '1'], ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => '400.00']]]));
        $this->app['auth']->forgetGuards();
        $html = $this->get('/verify/'.$sale->verify_token)->assertOk()->getContent();
        $this->assertStringContainsString($sale->invoice_no, $html);
        $this->assertStringContainsString('BDT 1,000.00', $html);
        $this->assertStringContainsString('PARTIAL', $html);
        foreach (['Secret Person', '01799887766', $this->shift->shift_no, 'Counter Cashier'] as $private) {
            $this->assertStringNotContainsString($private, $html, 'the page must not reveal who bought');
        }
        $this->get('/verify/'.str_repeat('z', 40))->assertNotFound();
        $this->get('/verify/short')->assertNotFound();
    }

    public function test_a_voided_invoice_says_so_on_the_verify_page(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '1']));
        $this->actingAsRole('manager');
        $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'test void'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->app['auth']->forgetGuards();
        $this->get('/verify/'.$sale->verify_token)->assertOk()->assertSee('VOID');
    }

    public function test_voiding_needs_a_reason_and_the_manager_permission(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '1']));
        $this->actingAsUser($this->cashier);
        $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'because'])->assertForbidden();
        $this->actingAsRole('manager');
        $this->postJson("/api/v1/sales/{$sale->id}/void", [])->assertStatus(422)->assertJsonPath('code', 'VALIDATION');
        $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'because'])->assertOk();
        $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'twice'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_VOID');
    }

    public function test_sales_list_filters_and_summary(): void
    {
        [$v] = $this->stocked(1, '20');
        $c = $this->customer();
        $this->checkout($this->payload([$v => '1']));
        $this->checkout($this->payload([$v => '2'], ['customer_id' => $c->id, 'payments' => [['method' => 'cash', 'amount' => '500.00']]]));
        $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'bkash', 'amount' => '1000.00', 'sender_number' => '01712345678', 'trx_id' => 'LIST1']]]));
        $this->actingAsRole('manager');
        $all = $this->getJson('/api/v1/sales')->assertOk();
        $this->assertSame([3, '4000.00', '2500.00', '1500.00'], [$all->json('summary.count'), $all->json('summary.grand_total'), $all->json('summary.paid_total'), $all->json('summary.due_total')]);
        $this->assertSame([1, 25, 3], [$all->json('meta.page'), $all->json('meta.per_page'), $all->json('meta.total')], 'the paging meta survives next to the summary');
        $this->assertCount(1, $this->getJson('/api/v1/sales?filter[due_only]=1')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/sales?filter[method]=bkash')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/sales?filter[customer_id]='.$c->id)->json('data'));
        $this->assertCount(3, $this->getJson('/api/v1/sales?filter[from]='.now('Asia/Dhaka')->toDateString().'&filter[to]='.now('Asia/Dhaka')->toDateString())->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/sales?filter[to]='.now('Asia/Dhaka')->subDays(2)->toDateString())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/sales?search='.Sale::query()->orderBy('id')->value('invoice_no'))->json('data'));
    }

    public function test_held_bills_round_trip_over_http(): void
    {
        [$v] = $this->stocked(1, '5');
        $this->actingAsUser($this->cashier);
        $id = $this->postJson('/api/v1/sales/hold', ['held_name' => 'Axio owner', 'payload' => ['lines' => [['variant_id' => $v, 'qty' => '1']]]])->assertCreated()->json('data.id');
        $this->assertSame(['Axio owner', 1], [$this->getJson('/api/v1/sales/held')->json('data.0.held_name'), $this->getJson('/api/v1/sales/held')->json('data.0.line_count')]);
        $this->assertSame($v, $this->postJson("/api/v1/sales/{$id}/resume")->assertOk()->json('data.lines.0.variant_id'));
        $this->getJson('/api/v1/sales/held')->assertJsonCount(0, 'data');
        $other = $this->postJson('/api/v1/sales/hold', ['payload' => ['lines' => []]])->json('data.id');
        $this->deleteJson("/api/v1/sales/{$other}/held")->assertOk();
        $this->getJson('/api/v1/sales/held')->assertJsonCount(0, 'data');
    }

    public function test_a_pending_mobile_payment_can_be_confirmed_once_by_someone_who_may_verify(): void
    {
        [$v] = $this->stocked(1, '5');
        app(SettingsService::class)->update(['require_verification_for_mfs' => true]);
        $this->checkout($this->payload([$v => '1'], ['payments' => [['method' => 'rocket', 'amount' => '1000.00', 'sender_number' => '01912345678', 'trx_id' => 'CONF1']]]));
        $p = SalePayment::query()->firstOrFail();
        $this->assertSame('pending', $p->verification);
        $this->actingAsUser($this->cashier);
        $this->postJson("/api/v1/sale-payments/{$p->id}/confirm")->assertForbidden();
        $this->actingAsRole('accountant');
        $this->postJson("/api/v1/sale-payments/{$p->id}/confirm")->assertOk()->assertJsonPath('data.verification', 'confirmed');
        $this->postJson("/api/v1/sale-payments/{$p->id}/confirm")->assertStatus(409);
        $this->assertSame($this->userWithRole('accountant')->id, $p->refresh()->verified_by);
    }

    public function test_pos_config_gives_the_terminal_what_it_needs_and_no_cost(): void
    {
        $this->actingAsUser($this->cashier);
        $d = $this->getJson('/api/v1/pos/config')->assertOk()->json('data');
        $this->assertSame(['exclusive', 5], [$d['settings']['vat_mode'], $d['settings']['cashier_max_discount_percent']]);
        $this->assertSame($this->walkIn()->id, $d['walk_in_customer_id']);
        $this->assertEqualsCanonicalizing(['cash', 'bkash', 'nagad', 'rocket', 'bank'], array_column($d['accounts'], 'account_type'));
        $this->assertSame($this->shift->id, $d['shift']['id']);
        $this->assertTrue($d['company_ready']);
        $this->assertFalse($d['can']['sell_below_floor']);
        $this->assertFalse($d['can']['view_cost']);
        $this->assertStringNotContainsString('cost', strtolower((string) json_encode($d['warehouses'])));
    }

    public function test_customer_endpoints_validate_search_and_summarise(): void
    {
        $this->actingAsUser($this->cashier);
        $this->postJson('/api/v1/customers', ['name' => 'Bad Phone', 'phones' => ['12345']])->assertStatus(422)->assertJsonPath('code', 'VALIDATION')->assertJsonStructure(['fields' => ['phones.0']]);
        $id = $this->postJson('/api/v1/customers', ['name' => 'Jamal Auto', 'phones' => ['01711223344'], 'opening_balance' => '800.00', 'credit_limit' => '5000.00'])->assertCreated()->json('data.id');
        $bare = $this->postJson('/api/v1/customers', ['name' => 'Only Name', 'phones' => ['01822334455']])->assertCreated();
        $this->assertSame(['0.00', '0.00', 'retail'], [$bare->json('data.credit_limit'), $bare->json('data.balance'), $bare->json('data.price_tier')], 'a new customer comes back with its defaults filled in');
        $this->postJson('/api/v1/customers', ['name' => 'Jamal Again', 'phones' => ['01711223344']])->assertStatus(409)->assertJsonPath('code', 'CUSTOMER_PHONE_EXISTS')->assertJsonPath('context.existing.id', $id);
        $this->postJson('/api/v1/customers', ['name' => 'Jamal Again', 'phones' => ['01711223344'], 'acknowledge_duplicate' => true])->assertCreated();
        $this->assertSame([$id], collect($this->getJson('/api/v1/customers?search=0171-122 3344')->json('data'))->pluck('id')->sort()->values()->take(1)->all());
        $this->assertSame('800.00', $this->getJson("/api/v1/customers/{$id}")->assertOk()->json('data.balance'));
        $o = $this->getJson("/api/v1/customers/{$id}/overview")->assertOk()->json('data');
        $this->assertSame(['800.00', '0.00', '0.00', 0], [$o['due'], $o['advance'], $o['lifetime_value'], $o['invoice_count']]);
        $this->assertCount(1, $this->getJson('/api/v1/customers?filter[with_due]=1')->json('data'));
        $this->assertSame(1, Customer::query()->where('is_walk_in', true)->count());
    }

    public function test_shift_endpoints_open_move_cash_and_close_with_a_z_report(): void
    {
        $manager = $this->userWithRole('manager');
        $this->actingAsUser($manager);
        $this->getJson('/api/v1/shifts/current')->assertOk()->assertJsonPath('data', null);
        $id = $this->postJson('/api/v1/shifts/open', ['cash_account_id' => $this->account('cash')->id, 'opening_float' => '2000.00'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/shifts/open', ['cash_account_id' => $this->account('cash')->id])->assertStatus(409)->assertJsonPath('code', 'SHIFT_ALREADY_OPEN');
        $this->postJson("/api/v1/shifts/{$id}/cash", ['direction' => 'out', 'amount' => '300.00', 'reason' => 'tea and snacks'])->assertOk()->assertJsonPath('data.totals.expected_cash', '1700.00');
        $this->get("/api/v1/shifts/{$id}/z-report")->assertStatus(409);
        $closed = $this->postJson("/api/v1/shifts/{$id}/close", ['denomination_counts' => ['1000' => 1, '500' => 1, '100' => 2], 'note' => 'ok'])->assertOk();
        $this->assertSame(['closed', '1700.00', '1700.00', '0.00', true], [$closed->json('data.status'), $closed->json('data.expected_cash'), $closed->json('data.counted_cash'), $closed->json('data.variance'), $closed->json('data.has_z_report')]);
        $this->assertSame('%PDF', substr($this->get("/api/v1/shifts/{$id}/z-report")->assertOk()->getContent(), 0, 4));
    }

    public function test_returns_over_http_wait_for_approval_and_hand_back_a_credit_note(): void
    {
        [$v] = $this->stocked(1, '5');
        $sale = $this->checkout($this->payload([$v => '2']));
        $line = $sale->items()->first()->id;
        $this->actingAsUser($this->cashier);
        $body = ['sale_id' => $sale->id, 'items' => [['sale_item_id' => $line, 'qty' => '1', 'condition' => 'resellable']], 'refund_mode' => 'cash'];
        $id = $this->postJson('/api/v1/sale-returns', $body)->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->postJson("/api/v1/sale-returns/{$id}/approve")->assertForbidden();
        $this->get("/api/v1/sale-returns/{$id}/pdf")->assertStatus(409);
        $this->actingAsRole('manager');
        $this->postJson("/api/v1/sale-returns/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.refund_total', '1000.00');
        $this->assertSame('%PDF', substr($this->get("/api/v1/sale-returns/{$id}/pdf")->assertOk()->getContent(), 0, 4));
        $this->postJson('/api/v1/sale-returns', ['sale_id' => $sale->id, 'items' => [['sale_item_id' => $line, 'qty' => '5', 'condition' => 'resellable']], 'refund_mode' => 'cash'])->assertStatus(422)->assertJsonPath('code', 'QTY_EXCEEDS_SOLD');
    }
}
