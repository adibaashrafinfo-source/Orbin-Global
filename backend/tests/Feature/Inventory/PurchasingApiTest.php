<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyProfile;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class PurchasingApiTest extends InventoryTestCase
{
    private function supplier(array $over = []): array
    {
        $this->actingAsRole('manager');

        return $this->postJson('/api/v1/suppliers', array_merge(['name' => 'Shanghai Glass Works', 'supplier_type' => 'import', 'phones' => ['01711000000']], $over))->assertCreated()->json('data');
    }

    private function po(array $supplier, array $variantIds, string $qty = '10', string $cost = '100'): array
    {
        return $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier['id'], 'order_date' => now('Asia/Dhaka')->toDateString(),
            'items' => array_map(fn ($v) => ['variant_id' => $v, 'qty_ordered' => $qty, 'unit_cost' => $cost, 'tax_rate_percent' => '5'], $variantIds),
        ])->assertCreated()->json('data');
    }

    public function test_supplier_lifecycle_code_opening_balance_and_guards(): void
    {
        $s = $this->supplier(['opening_balance' => '25000']);
        $this->assertMatchesRegularExpression('/^SUP-\d{4}$/', $s['code']);
        $this->assertSame('25000.00', $s['balance']);
        $this->assertSame('0.00', $this->supplier(['name' => 'Other', 'code' => 'XY-1'])['balance']);
        $this->postJson('/api/v1/suppliers', ['name' => 'Dup', 'code' => 'xy-1'])->assertStatus(422);
        $this->putJson("/api/v1/suppliers/{$s['id']}", ['opening_balance' => '1'])->assertStatus(422)->assertJsonStructure(['fields' => ['opening_balance']]);
        $this->putJson("/api/v1/suppliers/{$s['id']}", ['name' => 'Shanghai Glass', 'is_active' => false])->assertOk()->assertJsonPath('data.balance', '25000.00');
        $this->getJson('/api/v1/suppliers?search=shanghai')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.balance', '25000.00');
        $this->deleteJson("/api/v1/suppliers/{$s['id']}")->assertOk();
    }

    public function test_purchase_order_totals_workflow_and_edit_rules(): void
    {
        [$a, $b] = $this->variants(2);
        $s = $this->supplier();
        $po = $this->po($s, [$a, $b], '10', '100');
        // 2 lines × (10 × 100.00) = 2,000.00, tax 5% = 100.00, grand 2,100.00
        $this->assertSame(['draft', '2000.00', '100.00', '2100.00'], [$po['status'], $po['subtotal'], $po['tax_total'], $po['grand_total']]);
        $this->assertMatchesRegularExpression('/^ORB-PO-\d{4}-\d{5}$/', $po['po_no']);

        $this->putJson("/api/v1/purchase-orders/{$po['id']}", ['items' => [['variant_id' => $a, 'qty_ordered' => '4', 'unit_cost' => '250.50', 'tax_rate_percent' => '0']], 'extra_total' => '30'])->assertOk()
            ->assertJsonPath('data.subtotal', '1002.00')->assertJsonPath('data.grand_total', '1032.00')->assertJsonCount(1, 'data.items');
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', Auth::id());
        $this->putJson("/api/v1/purchase-orders/{$po['id']}", ['note' => 'late edit'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/approve")->assertStatus(409);
        $this->deleteJson("/api/v1/purchase-orders/{$po['id']}")->assertStatus(409);
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/purchase-orders?filter[status]=cancelled')->assertOk()->assertJsonPath('meta.total', 1);

        $empty = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $s['id'], 'order_date' => now()->toDateString(), 'items' => []]);
        $empty->assertStatus(422);
        $this->putJson("/api/v1/suppliers/{$s['id']}", ['is_active' => false])->assertOk();
        $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $s['id'], 'order_date' => now()->toDateString(), 'items' => [['variant_id' => $a, 'qty_ordered' => 1, 'unit_cost' => 1]]])
            ->assertStatus(422)->assertJsonPath('code', 'SUPPLIER_INACTIVE');
    }

    public function test_receiving_against_an_order_tracks_progress_and_blocks_over_receipt(): void
    {
        [$a, $b] = $this->variants(2);
        $s = $this->supplier();
        $po = $this->po($s, [$a, $b], '10', '100');
        $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s['id'], 'po_id' => $po['id'], 'received_date' => now()->toDateString()])->assertStatus(409); // still a draft order
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/approve")->assertOk();

        $draft = $this->getJson("/api/v1/purchase-orders/{$po['id']}/receipt-draft")->assertOk()->json('data');
        $this->assertCount(2, $draft['items']);
        $this->assertSame('10.000', $draft['items'][0]['qty_received']);

        $items = array_map(fn ($i) => ['po_item_id' => $i['po_item_id'], 'variant_id' => $i['variant_id'], 'qty_received' => '6', 'unit_cost' => '100'], $draft['items']);
        $grn = $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s['id'], 'po_id' => $po['id'], 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => $items])->assertCreated()->json('data');
        $this->postJson("/api/v1/goods-receipts/{$grn['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->getJson("/api/v1/purchase-orders/{$po['id']}")->assertOk()->assertJsonPath('data.status', 'partially_received')->assertJsonPath('data.items.0.qty_received', '6.000');

        // 6 more would make 12 of 10 ordered.
        $again = $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s['id'], 'po_id' => $po['id'], 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => $items])->assertCreated()->json('data');
        $this->postJson("/api/v1/goods-receipts/{$again['id']}/post")->assertStatus(422)->assertJsonPath('code', 'OVER_RECEIPT');
        $this->assertSame('6.000', $this->onHand($a), 'the refused receipt changed nothing');

        $rest = array_map(fn ($i) => ['po_item_id' => $i['po_item_id'], 'variant_id' => $i['variant_id'], 'qty_received' => '4', 'unit_cost' => '100'], $draft['items']);
        $this->putJson("/api/v1/goods-receipts/{$again['id']}", ['items' => $rest])->assertOk();
        $this->postJson("/api/v1/goods-receipts/{$again['id']}/post")->assertOk();
        $this->getJson("/api/v1/purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'received');
        $this->assertSame('10.000', $this->onHand($a));
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->getJson("/api/v1/variants/{$a}/purchase-history?supplier_id={$s['id']}")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.unit_cost', '100.00');
    }

    public function test_receipt_endpoints_preview_edit_cancel_and_foreign_po_item(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        $prev = $this->postJson('/api/v1/goods-receipts/preview', ['allocation_basis' => 'value', 'items' => [['variant_id' => $a, 'qty_received' => '10', 'unit_cost' => '100']], 'extra_costs' => [['cost_type' => 'freight', 'amount' => '500']]])->assertOk();
        $prev->assertJsonPath('data.lines.0.landed_unit_cost', '150.0000')->assertJsonPath('data.grand_total', '1500.00');
        $grn = $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s['id'], 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty_received' => '10', 'unit_cost' => '100']]])->assertCreated()->json('data');
        $this->putJson("/api/v1/goods-receipts/{$grn['id']}", ['allocation_basis' => 'quantity', 'extra_costs' => [['cost_type' => 'freight', 'amount' => '500']]])->assertOk()->assertJsonPath('data.grand_total', '1500.00')->assertJsonCount(1, 'data.items');
        $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s['id'], 'received_date' => now()->toDateString(), 'items' => [['po_item_id' => 99999, 'variant_id' => $a, 'qty_received' => '1', 'unit_cost' => '1']]])->assertStatus(422);
        $this->postJson("/api/v1/goods-receipts/{$grn['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/goods-receipts/{$grn['id']}/post")->assertStatus(409);
        $this->putJson("/api/v1/goods-receipts/{$grn['id']}", ['note' => 'x'])->assertStatus(409);
    }

    public function test_purchase_returns_reduce_stock_and_what_we_owe(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        $grn = $this->postJson('/api/v1/goods-receipts', ['warehouse_id' => $this->showroom, 'supplier_id' => $s['id'], 'received_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty_received' => '10', 'unit_cost' => '100', 'qty_damaged' => '1']]])->assertCreated()->json('data');
        $this->postJson("/api/v1/goods-receipts/{$grn['id']}/post")->assertOk();
        $this->assertSame('1000.00', $this->getJson("/api/v1/suppliers/{$s['id']}")->json('data.balance'));
        $this->assertSame('9.000', $this->onHand($a));

        $body = fn (string $qty) => ['supplier_id' => $s['id'], 'warehouse_id' => $this->showroom, 'grn_id' => $grn['id'], 'return_date' => now('Asia/Dhaka')->toDateString(), 'reason' => 'cracked', 'items' => [['variant_id' => $a, 'qty' => $qty, 'unit_cost' => '100']]];
        // 9 good units came in on this receipt (10 − 1 damaged); 10 cannot go back.
        $big = $this->postJson('/api/v1/purchase-returns', $body('10'))->assertCreated()->json('data');
        $this->postJson("/api/v1/purchase-returns/{$big['id']}/post")->assertStatus(422)->assertJsonPath('code', 'RETURN_EXCEEDS_RECEIPT');
        $this->putJson("/api/v1/purchase-returns/{$big['id']}", ['items' => [['variant_id' => $a, 'qty' => '3', 'unit_cost' => '100']]])->assertOk()->assertJsonPath('data.total', '300.00');
        $this->postJson("/api/v1/purchase-returns/{$big['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('6.000', $this->onHand($a));
        $this->assertSame('700.00', $this->getJson("/api/v1/suppliers/{$s['id']}")->json('data.balance'));

        $more = $this->postJson('/api/v1/purchase-returns', $body('7'))->assertCreated()->json('data');
        $this->postJson("/api/v1/purchase-returns/{$more['id']}/post")->assertStatus(422)->assertJsonPath('code', 'RETURN_EXCEEDS_RECEIPT');
        $this->postJson("/api/v1/purchase-returns/{$big['id']}/post")->assertStatus(409);
        // Without a receipt reference the only limit is the stock on hand.
        $loose = $this->postJson('/api/v1/purchase-returns', ['supplier_id' => $s['id'], 'warehouse_id' => $this->showroom, 'return_date' => now('Asia/Dhaka')->toDateString(), 'items' => [['variant_id' => $a, 'qty' => '7', 'unit_cost' => '100']]])->assertCreated()->json('data');
        $this->postJson("/api/v1/purchase-returns/{$loose['id']}/post")->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertSame('6.000', $this->onHand($a));
    }

    public function test_purchase_order_pdf_needs_a_company_profile_and_the_share_link_expires_and_cannot_be_forged(): void
    {
        [$a] = $this->variants();
        $s = $this->supplier();
        $po = $this->po($s, [$a]);
        $this->getJson("/api/v1/purchase-orders/{$po['id']}/pdf")->assertStatus(422)->assertJsonPath('code', 'COMPANY_PROFILE_INCOMPLETE');
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/share-link")->assertStatus(422);

        CompanyProfile::current()->update(['legal_name' => 'Orbin Global', 'address_line1' => '12 Mirpur Road', 'city' => 'Dhaka', 'phones' => ['01711000000']]);
        $pdf = $this->get("/api/v1/purchase-orders/{$po['id']}/pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $file = tempnam(sys_get_temp_dir(), 'po').'.pdf';
        file_put_contents($file, $pdf->getContent());
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
        if ($text !== '') {
            $this->assertStringContainsString($po['po_no'], $text);
            $this->assertStringContainsString('Shanghai Glass Works', $text);
            $this->assertStringContainsString('12 Mirpur Road', $text);
        }

        $url = $this->postJson("/api/v1/purchase-orders/{$po['id']}/share-link")->assertOk()->json('data.url');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get($url.'x')->assertStatus(403);
        $this->get(URL::route('shared.purchase-orders.pdf', ['purchaseOrder' => $po['id']]))->assertStatus(403);
        $this->travel(8)->days();
        $this->get($url)->assertStatus(403);
    }

    public function test_cost_documents_are_only_for_purchasing_roles(): void
    {
        $this->actingAsRole('cashier');
        $this->getJson('/api/v1/purchase-orders')->assertStatus(403);
        $this->getJson('/api/v1/suppliers')->assertStatus(403);
        $this->actingAsRole('storekeeper');
        $this->getJson('/api/v1/goods-receipts')->assertOk();
        $this->postJson('/api/v1/purchase-orders', [])->assertStatus(403);
        $this->postJson('/api/v1/suppliers', ['name' => 'x'])->assertStatus(403);
        $this->assertSame(0, Supplier::query()->count());
    }
}
