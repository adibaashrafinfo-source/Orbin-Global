<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Party payments with allocation, expenses and fund transfers (FR-1002, FR-1004, FR-1005), plus the small columns the
 * dues screens and write-offs need (FR-1007, FR-1008). Departures from the JSON are listed in docs/decisions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('code', 40)->nullable()->unique()->comment('set only on categories the system itself posts to, such as transfer charges');
            $table->string('name_en', 160);
            $table->string('name_bn', 160)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('expense_no', 40)->unique();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->date('expense_date')->index();
            $table->string('payee', 200)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->enum('status', ['pending_approval', 'posted', 'rejected', 'void'])->default('posted')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at', 3)->nullable();
            $table->string('decision_note', 255)->nullable()->comment('why it was rejected or voided');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT chk_expenses_amount CHECK (amount > 0)');

        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('transfer_no', 40)->unique();
            $table->foreignId('from_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->foreignId('fee_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->date('transfer_date')->index();
            $table->text('note')->nullable();
            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement('ALTER TABLE fund_transfers ADD CONSTRAINT chk_ft_amounts CHECK (amount > 0 AND fee_amount >= 0 AND from_account_id <> to_account_id)');

        Schema::create('party_payments', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('payment_no', 40)->unique();
            $table->enum('party_type', ['customer', 'supplier']);
            $table->unsignedBigInteger('party_id');
            $table->enum('direction', ['receive', 'pay']);
            $table->enum('method', ['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer']);
            $table->decimal('amount', 14, 2);
            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('sender_number', 20)->nullable();
            $table->string('trx_id', 60)->nullable()->index();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_ref', 120)->nullable();
            $table->date('payment_date')->index();
            $table->decimal('allocated_total', 14, 2)->default(0);
            $table->decimal('advance_amount', 14, 2)->default(0);
            $table->enum('verification', ['confirmed', 'pending', 'rejected'])->default('confirmed');
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('receipt_pdf_path', 255)->nullable();
            $table->text('note')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->string('trx_key', 100)->nullable()->unique()->comment('method:trx_id for mobile money while not overridden (BR-061)');
            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['party_type', 'party_id'], 'idx_pp_party');
        });
        DB::statement('ALTER TABLE party_payments ADD CONSTRAINT chk_pp_amounts CHECK (amount > 0 AND allocated_total >= 0 AND advance_amount >= 0 AND allocated_total + advance_amount = amount)');

        Schema::create('party_payment_allocations', function (Blueprint $table) {
            $table->orbinAppendOnly();
            $table->foreignId('payment_id')->constrained('party_payments')->restrictOnDelete();
            $table->string('doc_table', 40)->comment('sales, goods_receipts, or party_ledger_entries for an opening balance');
            $table->unsignedBigInteger('doc_id');
            $table->decimal('amount', 14, 2);
            $table->index(['doc_table', 'doc_id'], 'idx_ppa_doc');
        });
        DB::statement('ALTER TABLE party_payment_allocations ADD CONSTRAINT chk_ppa_amount CHECK (amount > 0)');

        foreach (['customers', 'suppliers'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->date('promise_date')->nullable();
                $table->string('promise_note', 255)->nullable();
            });
        }

        foreach (['sales', 'goods_receipts'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->decimal('written_off_total', 14, 2)->default(0);
            });
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT chk_{$t}_writeoff CHECK (written_off_total >= 0)");
        }
        // A written-off or discounted due is neither paid nor owed any more, so the sale identity gains a third term.
        DB::statement('ALTER TABLE sales DROP CONSTRAINT chk_sales_paid_due');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sales_paid_due CHECK (status IN ('draft', 'held') OR grand_total = paid_total + due_total + written_off_total)");

        Schema::table('purchase_extra_costs', function (Blueprint $table) {
            $table->foreign('account_id', 'fk_pec_account')->references('id')->on('financial_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_extra_costs', function (Blueprint $table) {
            $table->dropForeign('fk_pec_account');
        });
        DB::statement('ALTER TABLE sales DROP CONSTRAINT chk_sales_paid_due');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sales_paid_due CHECK (status IN ('draft', 'held') OR grand_total = paid_total + due_total)");
        foreach (['sales', 'goods_receipts'] as $t) {
            DB::statement("ALTER TABLE {$t} DROP CONSTRAINT chk_{$t}_writeoff");
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('written_off_total');
            });
        }
        foreach (['customers', 'suppliers'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['promise_date', 'promise_note']);
            });
        }
        foreach (['party_payment_allocations', 'party_payments', 'fund_transfers', 'expenses', 'expense_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
