<?php

namespace Tests\Feature\Finance;

use App\Models\CompanyProfile;
use App\Models\PartyPayment;
use App\Services\PermissionService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** The HTTP contract of Phase 4: shapes, validation, who sees what, and the documents. */
class FinanceApiTest extends FinanceTestCase
{
    private function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, $pdf);
        $out = (string) shell_exec('pdftotext -layout '.escapeshellarg($tmp).' - 2>&1');
        @unlink($tmp);

        return $out;
    }

    public function test_a_cashier_takes_a_payment_over_http_and_a_retry_with_the_same_key_is_the_same_payment(): void
    {
        $c = $this->customer();
        $one = $this->dueSale($c);
        $this->dueSale($c);
        $this->actingAsUser($this->cashier);
        $body = ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'cash', 'amount' => '1500.00', 'note' => 'collected at the counter'];

        $first = $this->withHeader('Idempotency-Key', 'k-1')->postJson('/api/v1/party-payments', $body)->assertCreated();
        $first->assertJsonPath('data.status', 'posted')->assertJsonPath('data.allocated_total', '1500.00')->assertJsonPath('data.advance_amount', '0.00')
            ->assertJsonPath('data.party.name', $c->name)->assertJsonPath('data.allocations.0.doc_no', $one->invoice_no)->assertJsonPath('data.allocations.0.amount', '1000.00')
            ->assertJsonPath('data.allocations.1.amount', '500.00')->assertJsonPath('data.has_receipt', true);
        $second = $this->withHeader('Idempotency-Key', 'k-1')->postJson('/api/v1/party-payments', $body)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, PartyPayment::query()->count());

        $this->getJson('/api/v1/party-payments/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.payment_no', $first->json('data.payment_no'));
    }

    public function test_bad_input_is_refused_with_field_errors_and_nothing_is_posted(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->cashier);
        $this->postJson('/api/v1/party-payments', ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'cash', 'amount' => '0'])->assertStatus(422)->assertJsonStructure(['fields' => ['amount']]);
        $this->postJson('/api/v1/party-payments', ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'cash', 'amount' => '10.123'])->assertStatus(422)->assertJsonStructure(['fields' => ['amount']]);
        $this->postJson('/api/v1/party-payments', ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'cheque', 'amount' => '10'])->assertStatus(422)->assertJsonStructure(['fields' => ['method']]);
        $this->postJson('/api/v1/party-payments', ['party_type' => 'customer', 'party_id' => 99999999, 'method' => 'cash', 'amount' => '10'])->assertStatus(422)->assertJsonStructure(['fields' => ['party_id']]);
        $this->postJson('/api/v1/party-payments', ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'bkash', 'amount' => '10'])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_REFERENCE_REQUIRED');
        $this->postJson('/api/v1/party-payments', ['party_type' => 'customer', 'party_id' => $c->id, 'method' => 'cash', 'amount' => '10', 'allocations' => [['doc_table' => 'users', 'doc_id' => 1, 'amount' => '10']]])->assertStatus(422)->assertJsonStructure(['fields']);
        $this->assertSame(0, PartyPayment::query()->count());
    }

    public function test_who_may_see_payments_and_their_receipts(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        $mine = $this->receive($c, '300.00');
        $other = $this->userWithRole('manager');
        $theirs = $this->receive($c, '200.00', [], $other);

        $this->actingAsUser($this->cashier);
        $this->getJson('/api/v1/party-payments')->assertForbidden();
        $this->getJson("/api/v1/party-payments/{$mine->id}")->assertOk();
        $this->getJson("/api/v1/party-payments/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/v1/party-payments/{$theirs->id}/receipt")->assertForbidden();
        $this->getJson("/api/v1/party-payments/{$mine->id}/receipt")->assertOk();
        $this->getJson('/api/v1/party-ledger?party_type=customer&party_id='.$c->id)->assertForbidden();

        $this->actingAsUser($this->accountant);
        $list = $this->getJson('/api/v1/party-payments?filter[party_type]=customer&filter[party_id]='.$c->id)->assertOk();
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], array_column($list->json('data'), 'id'));
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/party-payments?search='.$mine->payment_no)->json('data'), 'id'));
        $this->assertSame(2, $this->getJson('/api/v1/party-payments')->json('meta.total'));
    }

    public function test_the_open_documents_endpoint_gives_the_split_options_before_anything_is_posted(): void
    {
        $c = $this->customer(['opening_balance' => '300.00']);
        $sale = $this->dueSale($c);
        $this->actingAsUser($this->cashier);
        $res = $this->getJson('/api/v1/party-payments/open-documents?party_type=customer&party_id='.$c->id)->assertOk();
        $res->assertJsonPath('data.balance', '1300.00')->assertJsonPath('data.documents.0.no', 'OPENING')->assertJsonPath('data.documents.0.open', '300.00')
            ->assertJsonPath('data.documents.1.no', $sale->invoice_no)->assertJsonPath('data.documents.1.table', 'sales');
        $this->getJson('/api/v1/party-payments/open-documents?party_type=supplier&party_id=1')->assertForbidden();
        $this->assertSame(0, PartyPayment::query()->count());
    }

    public function test_voiding_and_confirming_need_the_verify_permission(): void
    {
        $c = $this->customer();
        $p = $this->receive($c, '100.00');
        $this->actingAsUser($this->cashier);
        $this->postJson("/api/v1/party-payments/{$p->id}/void", ['reason' => 'mistake'])->assertForbidden();
        $this->actingAsUser($this->accountant);
        $this->postJson("/api/v1/party-payments/{$p->id}/void", [])->assertStatus(422)->assertJsonStructure(['fields' => ['reason']]);
        $this->postJson("/api/v1/party-payments/{$p->id}/void", ['reason' => 'mistake'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson("/api/v1/party-payments/{$p->id}/void", ['reason' => 'mistake'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_VOID');
    }

    public function test_the_receipt_pdf_carries_the_figures_and_marks_a_void(): void
    {
        $c = $this->customer(['name' => 'Receipt Garage']);
        $this->dueSale($c);
        $p = $this->receive($c, '1200.00', ['method' => 'bkash', 'sender_number' => '01712345678', 'trx_id' => 'RCP-1']);
        $this->actingAsUser($this->accountant);
        $res = $this->get("/api/v1/party-payments/{$p->id}/receipt")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $bytes = $res->streamedContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $text = $this->pdfText($bytes);
        foreach (['PAYMENT RECEIPT', $p->payment_no, 'Receipt Garage', 'bKash', 'RCP-1', '1,200.00', 'One Thousand Two Hundred', 'advance', 'Orbin Global'] as $needle) {
            $this->assertStringContainsString($needle, $text, "receipt shows {$needle}");
        }
        $this->assertStringNotContainsString('VOID', $text);

        $this->postJson("/api/v1/party-payments/{$p->id}/void", ['reason' => 'wrong customer'])->assertOk();
        $voided = $this->pdfText($this->get("/api/v1/party-payments/{$p->id}/receipt")->streamedContent());
        $this->assertStringContainsString('VOID', $voided);
        $this->assertStringContainsString('wrong customer', $voided);
    }

    public function test_a_supplier_payment_prints_as_a_voucher(): void
    {
        [$supplier] = $this->supplierWithReceipt();
        $this->fund('cash', '2000.00');
        $p = $this->pay($supplier, '400.00');
        $this->actingAsUser($this->accountant);
        $text = $this->pdfText($this->get("/api/v1/party-payments/{$p->id}/receipt")->streamedContent());
        $this->assertStringContainsString('PAYMENT VOUCHER', $text);
        $this->assertStringContainsString('PAID TO', $text);
        $this->assertStringContainsString('600.00', $text, 'the 600 still owed after paying 400 of 1,000');
    }

    public function test_the_receipt_waits_for_a_complete_company_profile_but_the_payment_stands(): void
    {
        CompanyProfile::current()->forceFill(['legal_name' => ''])->save();
        $c = $this->customer();
        $p = $this->receive($c, '50.00');
        $this->assertSame('posted', $p->status);
        $this->assertNull($p->receipt_pdf_path);
        $this->actingAsUser($this->accountant);
        $this->getJson("/api/v1/party-payments/{$p->id}/receipt")->assertStatus(422)->assertJsonPath('code', 'COMPANY_PROFILE_INCOMPLETE');
    }

    public function test_the_party_ledger_and_statement_pdf(): void
    {
        $c = $this->customer(['name' => 'Statement Garage', 'opening_balance' => '500.00']);
        $this->dueSale($c, 2, '500.00');
        $this->receive($c, '700.00');
        $this->actingAsUser($this->accountant);

        $res = $this->getJson('/api/v1/party-ledger?party_type=customer&party_id='.$c->id)->assertOk();
        // 500 brought forward + 2,000 billed − 500 paid at the till − 700 received = 1,300.
        $res->assertJsonPath('data.opening', '0.00')->assertJsonPath('data.closing', '1300.00')->assertJsonPath('data.party.balance', '1300.00')
            ->assertJsonPath('data.total_debit', '2500.00')->assertJsonPath('data.total_credit', '1200.00');
        $types = array_column($res->json('data.rows'), 'type');
        $this->assertSame(['opening', 'invoice', 'payment', 'payment'], $types);
        $this->assertSame(['500.00', '2500.00', '2000.00', '1300.00'], array_column($res->json('data.rows'), 'balance'));

        $tomorrow = now('Asia/Dhaka')->addDay()->toDateString();
        $this->getJson('/api/v1/party-ledger?party_type=customer&party_id='.$c->id.'&from='.$tomorrow)->assertOk()->assertJsonPath('data.opening', '1300.00')->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/party-ledger?party_type=customer')->assertStatus(422);

        $pdf = $this->get('/api/v1/party-ledger/statement.pdf?party_type=customer&party_id='.$c->id)->assertOk();
        $this->assertStringContainsString('Statement_'.$c->code.'.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $text = $this->pdfText($pdf->getContent());
        foreach (['CUSTOMER STATEMENT', 'Statement Garage', 'Opening balance', '1,300.00', 'AMOUNT DUE FROM YOU'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_a_long_statement_is_written_in_slices_without_losing_a_row_at_the_joins(): void
    {
        $c = $this->customer(['name' => 'Busy Garage']);
        $rows = [];
        for ($i = 0; $i < 450; $i++) {
            $rows[] = ['created_at' => now(), 'branch_id' => 1, 'party_type' => 'customer', 'party_id' => $c->id, 'entry_date' => now('Asia/Dhaka')->subDays(450 - $i)->toDateString(), 'entry_type' => 'invoice', 'debit' => '10.00', 'credit' => '0.00', 'ref_no' => sprintf('LONG-%03d', $i), 'description' => 'Row '.$i];
        }
        foreach (array_chunk($rows, 150) as $chunk) {
            DB::table('party_ledger_entries')->insert($chunk);
        }
        $this->actingAsUser($this->accountant);
        $pdf = $this->get('/api/v1/party-ledger/statement.pdf?party_type=customer&party_id='.$c->id)->assertOk()->getContent();
        $text = $this->pdfText($pdf);
        // The slices end at rows 199, 399 and the last; every row on both sides of each join must be there, in order.
        $positions = [];
        foreach (['LONG-000', 'LONG-199', 'LONG-200', 'LONG-399', 'LONG-400', 'LONG-449'] as $ref) {
            $this->assertStringContainsString($ref, $text, "{$ref} is on the statement");
            $positions[] = strpos($text, $ref);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'rows keep their order across slices');
        $this->assertSame(450, preg_match_all('/LONG-\d{3}/', $text));
        $this->assertStringContainsString('4,500.00', $text);
        $this->assertStringContainsString('AMOUNT DUE FROM YOU', $text);
        preg_match('/Page \d+ of (\d+)/', $text, $pages);
        $this->assertGreaterThan(5, (int) ($pages[1] ?? 0), 'a long history runs over many pages');
    }

    public function test_a_supplier_statement_reads_from_our_side(): void
    {
        [$supplier] = $this->supplierWithReceipt('10', '100');
        $this->fund('cash', '1000.00');
        $this->pay($supplier, '400.00');
        $this->actingAsUser($this->accountant);
        $res = $this->getJson('/api/v1/party-ledger?party_type=supplier&party_id='.$supplier->id)->assertOk();
        $res->assertJsonPath('data.closing', '600.00')->assertJsonPath('data.total_credit', '1000.00')->assertJsonPath('data.total_debit', '400.00');
        $text = $this->pdfText($this->get('/api/v1/party-ledger/statement.pdf?party_type=supplier&party_id='.$supplier->id)->getContent());
        $this->assertStringContainsString('SUPPLIER STATEMENT', $text);
        $this->assertStringContainsString('AMOUNT WE OWE', $text);
    }

    public function test_accounts_show_balances_to_those_who_may_and_only_names_to_cashiers(): void
    {
        $this->fund('bank', '1234.50');
        $this->actingAsUser($this->cashier);
        $names = $this->getJson('/api/v1/financial-accounts')->assertOk();
        $this->assertCount(5, $names->json('data'));
        $this->assertArrayNotHasKey('balance', $names->json('data.0'));
        $this->assertArrayNotHasKey('opening_balance', $names->json('data.0'));
        $this->getJson('/api/v1/financial-accounts/'.$this->account('bank')->id)->assertForbidden();

        $this->actingAsUser($this->accountant);
        $full = collect($this->getJson('/api/v1/financial-accounts')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame('1234.50', $full['BANK-01']['balance']);
        $this->getJson('/api/v1/financial-accounts/'.$this->account('bank')->id)->assertOk()->assertJsonPath('data.balance', '1234.50');
        $this->getJson('/api/v1/financial-accounts?filter[is_active]=false')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_accounts_are_managed_over_http_and_their_ledger_runs_with_a_balance(): void
    {
        $this->actingAsUser($this->accountant);
        $id = $this->postJson('/api/v1/financial-accounts', ['code' => 'BANK-02', 'name' => 'Dutch-Bangla', 'account_type' => 'bank', 'bank_name' => 'Dutch-Bangla Bank', 'opening_balance' => '1000.00', 'opening_date' => now('Asia/Dhaka')->toDateString()])
            ->assertCreated()->assertJsonPath('data.balance', '1000.00')->json('data.id');
        $this->postJson('/api/v1/financial-accounts', ['code' => 'BANK-02', 'name' => 'dup', 'account_type' => 'bank'])->assertStatus(422)->assertJsonStructure(['fields' => ['code']]);
        $this->postJson('/api/v1/financial-accounts', ['code' => 'X', 'name' => 'x', 'account_type' => 'cheque'])->assertStatus(422)->assertJsonStructure(['fields' => ['account_type']]);
        $this->postJson("/api/v1/financial-accounts/{$id}/adjust", ['direction' => 'in', 'amount' => '250.00', 'reason' => 'missed deposit'])->assertOk()->assertJsonPath('data.balance', '1250.00');
        $this->postJson("/api/v1/financial-accounts/{$id}/adjust", ['direction' => 'out', 'amount' => '50.00', 'reason' => 'bank fee'])->assertOk()->assertJsonPath('data.balance', '1200.00');
        $this->postJson("/api/v1/financial-accounts/{$id}/adjust", ['direction' => 'out', 'amount' => '50.00'])->assertStatus(422)->assertJsonStructure(['fields' => ['reason']]);
        $rows = $this->getJson("/api/v1/financial-accounts/{$id}/transactions")->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame(['1200.00', '1250.00'], array_column($rows, 'balance'), 'newest first, each with the balance after it');
        $this->putJson("/api/v1/financial-accounts/{$id}", ['name' => 'Dutch-Bangla Bank'])->assertOk()->assertJsonPath('data.name', 'Dutch-Bangla Bank');
        $this->putJson("/api/v1/financial-accounts/{$id}", ['opening_balance' => '5.00'])->assertStatus(422)->assertJsonPath('code', 'OPENING_LOCKED');
        $this->deleteJson("/api/v1/financial-accounts/{$id}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');
    }

    public function test_expenses_run_through_their_life_over_http(): void
    {
        $this->fund('bank', '50000.00');
        $this->actingAsUser($this->accountant);
        $category = DB::table('expense_categories')->where('name_en', 'Shop rent')->value('id');
        $this->putJson('/api/v1/settings/app', [])->assertForbidden();
        DB::table('app_settings')->updateOrInsert(['key' => 'expense_approval_threshold'], ['value' => json_encode(10000), 'group_name' => 'finance', 'created_at' => now(), 'updated_at' => now()]);
        app(SettingsService::class)->forgetCache();

        $small = $this->postJson('/api/v1/expenses', ['category_id' => $category, 'amount' => '500.00', 'account_id' => $this->account('bank')->id, 'payee' => 'Landlord'])->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.category.name_en', 'Shop rent');
        $big = $this->postJson('/api/v1/expenses', ['category_id' => $category, 'amount' => '20000.00', 'account_id' => $this->account('bank')->id])->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');
        $this->postJson("/api/v1/expenses/{$big}/approve")->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        $this->actingAsUser($this->userWithRole('manager'));
        $this->postJson("/api/v1/expenses/{$big}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/expenses/{$big}/approve")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson('/api/v1/expenses/'.$small->json('data.id').'/void', ['reason' => 'duplicate'])->assertOk()->assertJsonPath('data.status', 'void');
        $list = $this->getJson('/api/v1/expenses?filter[status]=void')->assertOk();
        $this->assertSame([$small->json('data.id')], array_column($list->json('data'), 'id'));
        $this->getJson("/api/v1/expenses/{$big}")->assertOk()->assertJsonPath('data.amount', '20000.00');
        $this->assertSame(1, count($this->getJson('/api/v1/expenses?search=Landlord')->json('data')));
    }

    public function test_expense_categories_are_admin_editable_but_the_system_one_is_protected(): void
    {
        $this->actingAsUser($this->cashier);
        $this->getJson('/api/v1/expense-categories')->assertOk()->assertJsonCount(11, 'data');
        $this->postJson('/api/v1/expense-categories', ['name_en' => 'Tea'])->assertForbidden();

        $this->actingAsUser($this->accountant);
        $id = $this->postJson('/api/v1/expense-categories', ['name_en' => 'Tea and snacks', 'name_bn' => 'চা'])->assertCreated()->assertJsonPath('data.is_system', false)->json('data.id');
        $this->putJson("/api/v1/expense-categories/{$id}", ['name_en' => 'Tea', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/api/v1/expense-categories/{$id}")->assertNoContent();
        $fee = DB::table('expense_categories')->where('code', 'transfer_fee')->value('id');
        $this->putJson("/api/v1/expense-categories/{$fee}", ['is_active' => false])->assertStatus(422)->assertJsonPath('code', 'SYSTEM_CATEGORY');
        $this->deleteJson("/api/v1/expense-categories/{$fee}")->assertStatus(422)->assertJsonPath('code', 'SYSTEM_CATEGORY');
        $used = DB::table('expense_categories')->where('name_en', 'Shop rent')->value('id');
        $this->fund('bank', '1000.00');
        $this->postJson('/api/v1/expenses', ['category_id' => $used, 'amount' => '10.00', 'account_id' => $this->account('bank')->id])->assertCreated();
        $this->deleteJson("/api/v1/expense-categories/{$used}")->assertStatus(409)->assertJsonPath('code', 'IN_USE');
        $this->putJson("/api/v1/expense-categories/{$used}", ['parent_id' => $used])->assertStatus(422);
    }

    public function test_fund_transfers_over_http(): void
    {
        $this->fund('bkash', '5000.00');
        $this->actingAsUser($this->accountant);
        $body = ['from_account_id' => $this->account('bkash')->id, 'to_account_id' => $this->account('bank')->id, 'amount' => '2000.00', 'fee_amount' => '30.00', 'note' => 'deposit'];
        $id = $this->postJson('/api/v1/fund-transfers', $body)->assertCreated()->assertJsonPath('data.from.name', 'bKash')->assertJsonPath('data.to.name', 'Bank Account')->assertJsonPath('data.status', 'posted')->json('data.id');
        $this->postJson('/api/v1/fund-transfers', ['to_account_id' => $this->account('bkash')->id] + $body)->assertStatus(422)->assertJsonStructure(['fields' => ['to_account_id']]);
        $this->postJson('/api/v1/fund-transfers', ['amount' => '99999.00'] + $body)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_FUNDS');
        $this->getJson('/api/v1/fund-transfers?filter[account_id]='.$this->account('bank')->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/fund-transfers/{$id}")->assertOk()->assertJsonPath('data.fee_amount', '30.00');
        $this->postJson("/api/v1/fund-transfers/{$id}/void", ['reason' => 'wrong account'])->assertOk()->assertJsonPath('data.status', 'void');
    }

    public function test_the_day_book_and_dues_endpoints(): void
    {
        $c = $this->customer(['name' => 'Dues Garage']);
        $this->dueSale($c, 2);
        $this->checkout($this->payload([$this->variant => '1']));
        $this->actingAsUser($this->accountant);
        $today = now('Asia/Dhaka')->toDateString();

        $book = $this->getJson("/api/v1/day-book?from={$today}&to={$today}")->assertOk();
        $this->assertCount(5, $book->json('data.accounts'));
        $book->assertJsonPath('meta.total', 2)->assertJsonPath('data.totals.in', '2000.00');
        $this->getJson("/api/v1/day-book?from={$today}&to=2000-01-01")->assertStatus(422);
        $this->getJson('/api/v1/day-book')->assertOk();

        $dues = $this->getJson('/api/v1/dues?type=customer')->assertOk();
        $dues->assertJsonPath('data.0.name', 'Dues Garage')->assertJsonPath('data.0.balance', '2000.00')->assertJsonPath('summary.total', '2000.00')->assertJsonPath('summary.buckets.d0_15', '2000.00');
        $this->getJson('/api/v1/dues?type=other')->assertStatus(422);
        $this->getJson('/api/v1/dues?type=customer&bucket=nope')->assertStatus(422);
        $this->getJson('/api/v1/dues?type=supplier')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_promise_to_pay_follows_who_may_collect_from_customers_and_who_may_pay_suppliers(): void
    {
        $c = $this->customer();
        [$supplier] = $this->supplierWithReceipt();
        $when = now('Asia/Dhaka')->addDays(5)->toDateString();
        $this->actingAsUser($this->cashier);
        $this->putJson('/api/v1/dues/promise', ['party_type' => 'customer', 'party_id' => $c->id, 'promise_date' => $when, 'promise_note' => 'after Eid'])->assertOk()->assertJsonPath('data.promise_date', $when);
        $this->putJson('/api/v1/dues/promise', ['party_type' => 'supplier', 'party_id' => $supplier->id, 'promise_date' => $when])->assertForbidden();
        $this->putJson('/api/v1/dues/promise', ['party_type' => 'customer', 'party_id' => $c->id, 'promise_date' => 'soon'])->assertStatus(422);
        $this->putJson('/api/v1/dues/promise', ['party_type' => 'customer', 'party_id' => 99999999, 'promise_date' => $when])->assertStatus(404);
        $this->actingAsUser($this->accountant);
        $this->putJson('/api/v1/dues/promise', ['party_type' => 'supplier', 'party_id' => $supplier->id, 'promise_date' => null])->assertOk()->assertJsonPath('data.promise_date', null);
    }

    public function test_write_offs_and_adjustments_are_for_the_accountant_with_a_reason(): void
    {
        $c = $this->customer();
        $this->dueSale($c);
        $body = ['party_type' => 'customer', 'party_id' => $c->id, 'amount' => '200.00', 'kind' => 'writeoff', 'reason' => 'bad debt'];
        $this->actingAsUser($this->userWithRole('manager'));
        $this->postJson('/api/v1/party-ledger/write-offs', $body)->assertForbidden();
        $this->postJson('/api/v1/party-ledger/adjustments', ['direction' => 'increase'] + $body)->assertForbidden();

        $this->actingAsUser($this->accountant);
        $this->postJson('/api/v1/party-ledger/write-offs', ['reason' => ''] + $body)->assertStatus(422)->assertJsonStructure(['fields' => ['reason']]);
        $this->postJson('/api/v1/party-ledger/write-offs', $body)->assertCreated()->assertJsonPath('data.balance', '800.00')->assertJsonPath('data.documents.0.amount', '200.00');
        $this->postJson('/api/v1/party-ledger/adjustments', ['direction' => 'increase', 'amount' => '75.00', 'reason' => 'fix'] + $body)->assertCreated()->assertJsonPath('data.balance', '875.00');
        $this->postJson('/api/v1/party-ledger/adjustments', ['direction' => 'sideways'] + $body)->assertStatus(422)->assertJsonStructure(['fields' => ['direction']]);
    }

    public function test_the_period_lock_belongs_to_those_with_the_permission_and_only_the_owner_may_move_it_back(): void
    {
        $yesterday = now('Asia/Dhaka')->subDay()->toDateString();
        $lastWeek = now('Asia/Dhaka')->subDays(7)->toDateString();
        $this->actingAsUser($this->accountant);
        $this->getJson('/api/v1/settings/period-lock')->assertOk()->assertJsonPath('data.lock_date', null);
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => $yesterday])->assertForbidden();

        $owner = $this->actingAsRole('super_admin');
        $this->postJson('/api/v1/settings/period-lock', [])->assertStatus(422);
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => now('Asia/Dhaka')->addDay()->toDateString()])->assertStatus(422);
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => $yesterday])->assertOk()->assertJsonPath('data.lock_date', $yesterday);
        $this->assertDatabaseHas('audit_logs', ['action' => 'period_lock', 'actor_id' => $owner->id]);

        // Give the manager the permission: they may lock further forward, but not reopen closed books.
        DB::table('role_permissions')->updateOrInsert(['role' => 'manager', 'permission_key' => 'periods.lock'], ['allowed' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(PermissionService::class)->forget('manager');
        $this->actingAsUser($this->userWithRole('manager'));
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => now('Asia/Dhaka')->toDateString()])->assertOk();
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => $lastWeek])->assertForbidden();
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => null])->assertForbidden();
        $this->actingAsUser($owner);
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => $lastWeek])->assertOk()->assertJsonPath('data.lock_date', $lastWeek);
        $this->postJson('/api/v1/settings/period-lock', ['lock_date' => null])->assertOk()->assertJsonPath('data.lock_date', null);
    }

    public function test_the_general_settings_form_can_no_longer_change_the_lock_date(): void
    {
        $this->actingAsRole('super_admin');
        $res = $this->putJson('/api/v1/settings/app', ['period_lock_date' => now('Asia/Dhaka')->toDateString(), 'expense_approval_threshold' => 5000])->assertOk();
        $res->assertJsonPath('data.period_lock_date', null)->assertJsonPath('data.expense_approval_threshold', 5000);
        $this->assertArrayNotHasKey('period_lock_date', $res->json('meta.schema'));
    }

    public function test_the_z_report_counts_what_the_cashier_collected_and_paid_out_of_the_drawer(): void
    {
        $c = $this->customer();
        $this->dueSale($c, 2);
        $this->receive($c, '700.00');
        $this->actingAsUser($this->cashier);
        $totals = $this->getJson('/api/v1/shifts/'.$this->shift->id)->assertOk()->json('data');
        // Float 1,000 + 700 collected from a customer = 1,700 expected.
        $this->assertSame('1700.00', $totals['expected_cash'] ?? $totals['totals']['expected_cash']);
        $this->assertSame('700.00', $totals['totals']['cash_collections']);
        // A voided receipt drops out of the drawer figures.
        $p = PartyPayment::query()->latest('id')->firstOrFail();
        $this->actingAsUser($this->accountant);
        $this->postJson("/api/v1/party-payments/{$p->id}/void", ['reason' => 'xyz'])->assertOk();
        $this->actingAsUser($this->cashier);
        $this->assertSame('1000.00', $this->getJson('/api/v1/shifts/'.$this->shift->id)->json('data.totals.expected_cash'));
    }
}
