<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shifts, invoices, payments, returns, quotations and warranty claims (FR-700, FR-800). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('shift_no', 40)->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->dateTime('opened_at', 3)->useCurrent();
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->dateTime('closed_at', 3)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->json('denomination_counts')->nullable();
            $table->json('totals')->nullable();
            $table->text('note')->nullable();
            $table->string('z_report_path', 255)->nullable();
            $table->string('open_key', 60)->nullable()->unique()->comment('cashier_id:cash_account_id while open, NULL when closed');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('invoice_no', 40)->nullable()->unique();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->string('verify_token', 64)->nullable()->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->json('customer_snapshot');
            $table->json('company_snapshot');
            $table->json('vehicle_snapshot')->nullable();
            $table->enum('sale_channel', ['counter', 'phone', 'wholesale', 'online_order'])->default('counter');
            $table->enum('status', ['draft', 'held', 'completed', 'void', 'partially_returned', 'returned'])->default('draft')->index();
            $table->dateTime('sale_date', 3)->useCurrent()->index();
            $table->date('due_date')->nullable();
            $table->enum('vat_mode_snapshot', ['inclusive', 'exclusive'])->default('exclusive');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('line_discount_total', 14, 2)->default(0);
            $table->enum('cart_discount_type', ['none', 'percent', 'amount'])->default('none');
            $table->decimal('cart_discount_value', 14, 2)->default(0);
            $table->decimal('cart_discount_amount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('delivery_charge', 14, 2)->default(0);
            $table->decimal('round_off', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->decimal('due_total', 14, 2)->default(0);
            $table->decimal('change_given', 14, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('held_name', 160)->nullable();
            $table->json('held_payload')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->dateTime('pdf_generated_at', 3)->nullable();
            $table->unsignedInteger('reprint_count')->default(0);
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at', 3)->nullable();
            $table->index(['branch_id', 'sale_date'], 'idx_sales_branch_date');
            $table->index(['customer_id', 'sale_date'], 'idx_sales_customer_date');
            $table->index(['payment_status', 'due_total'], 'idx_sales_due');
        });
        DB::statement("ALTER TABLE sales ADD CONSTRAINT chk_sales_paid_due CHECK (status IN ('draft', 'held') OR grand_total = paid_total + due_total)");

        Schema::create('sale_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('product_name_snapshot', 255);
            $table->string('sku_snapshot', 80);
            $table->json('spec_snapshot');
            $table->decimal('qty', 14, 3);
            $table->decimal('qty_returned', 14, 3)->default(0);
            $table->decimal('unit_price', 14, 2);
            $table->enum('price_tier_used', ['retail', 'wholesale', 'dealer'])->default('retail');
            $table->enum('discount_type', ['none', 'percent', 'amount'])->default('none');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('cart_discount_share', 14, 2)->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->unsignedInteger('warranty_months')->default(0);
            $table->date('warranty_until')->nullable();
            $table->unsignedInteger('bundle_parent_line')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('price_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('price_override_reason', 255)->nullable();
            $table->unique(['sale_id', 'line_no']);
        });
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_si_qty CHECK (qty > 0 AND qty_returned >= 0 AND qty_returned <= qty)');

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'])->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('tendered', 14, 2)->nullable();
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('sender_number', 30)->nullable();
            $table->string('trx_id', 60)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_no', 60)->nullable();
            $table->string('bank_ref', 80)->nullable();
            $table->date('transfer_date')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->enum('verification', ['confirmed', 'pending', 'rejected'])->default('confirmed');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at', 3)->nullable();
            $table->foreignId('duplicate_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at', 3)->useCurrent();
            $table->string('trx_key', 100)->nullable()->unique()->comment('method:trx_id for mobile money while not rejected and not overridden (BR-061)');
            $table->index(['method', 'trx_id'], 'idx_sp_method_trx');
        });
        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT chk_sp_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT chk_sp_mfs_refs CHECK (method NOT IN ('bkash', 'nagad', 'rocket') OR (sender_number IS NOT NULL AND trx_id IS NOT NULL))");
        DB::statement("ALTER TABLE sale_payments ADD CONSTRAINT chk_sp_bank_refs CHECK (method <> 'bank_transfer' OR (bank_name IS NOT NULL AND bank_ref IS NOT NULL))");

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('return_no', 40)->unique();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'cancelled'])->default('draft')->index();
            $table->dateTime('return_date', 3)->useCurrent();
            $table->foreignId('reason_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->enum('refund_mode', ['original_method', 'cash', 'other_method', 'ledger_credit'])->default('ledger_credit');
            $table->enum('refund_method', ['cash', 'bkash', 'nagad', 'rocket', 'bank_transfer'])->nullable();
            $table->foreignId('refund_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('refund_total', 14, 2)->default(0);
            $table->decimal('ledger_credit_total', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->string('pdf_path', 255)->nullable();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->enum('condition', ['resellable', 'broken']);
            $table->foreignId('restock_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
        });
        DB::statement('ALTER TABLE sale_return_items ADD CONSTRAINT chk_sri_qty CHECK (qty > 0)');

        Schema::create('quotations', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('quote_no', 40)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('customer_snapshot');
            $table->json('vehicle_snapshot')->nullable();
            $table->enum('status', ['draft', 'sent', 'accepted', 'expired', 'converted', 'cancelled'])->default('draft')->index();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('delivery_charge', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('converted_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('pdf_path', 255)->nullable();
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->text('description_snapshot');
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->enum('discount_type', ['none', 'percent', 'amount'])->default('none');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });
        DB::statement('ALTER TABLE quotation_items ADD CONSTRAINT chk_qi_qty CHECK (qty > 0)');

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('claim_no', 40)->unique();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->enum('status', ['open', 'in_review', 'replaced', 'refunded', 'rejected'])->default('open')->index();
            $table->text('issue');
            $table->dateTime('claimed_at', 3)->useCurrent();
            $table->foreignId('photo_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('replacement_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->dateTime('resolved_at', 3)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['warranty_claims', 'quotation_items', 'quotations', 'sale_return_items', 'sale_returns', 'sale_payments', 'sale_items', 'sales', 'cash_shifts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
