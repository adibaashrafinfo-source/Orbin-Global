<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('code', 40)->unique();
            $table->string('name', 191)->index();
            $table->string('contact_person')->nullable();
            $table->json('phones')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Bangladesh');
            $table->enum('supplier_type', ['local', 'import'])->default('local');
            $table->integer('payment_terms_days')->default(0);
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('opening_balance', 14, 2)->default(0)->comment('amount we owe at go-live');
            $table->json('bank_details')->nullable();
            $table->text('notes')->nullable();
            $table->json('custom')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('po_no', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->enum('status', ['draft', 'approved', 'partially_received', 'received', 'closed', 'cancelled'])->default('draft')->index();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('extra_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->string('pdf_path')->nullable();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_ordered', 14, 3);
            $table->decimal('qty_received', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2);
        });
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT chk_poi_qty CHECK (qty_ordered > 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT chk_poi_cost CHECK (unit_cost >= 0)');

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('grn_no', 40)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('po_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->string('supplier_invoice_no')->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('received_date')->index();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft')->index();
            $table->enum('allocation_basis', ['value', 'quantity'])->default('value');
            $table->decimal('items_total', 14, 2)->default(0);
            $table->decimal('extra_cost_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->dateTime('posted_at', 3)->nullable();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('grn_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('po_item_id')->nullable()->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_received', 14, 3);
            $table->decimal('qty_damaged', 14, 3)->default(0)->comment('damaged on arrival; posts a damage movement');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('allocated_extra', 14, 2)->default(0);
            $table->decimal('landed_unit_cost', 14, 4)->default(0)->comment('(good qty * unit cost + allocated extra) / good qty');
            $table->decimal('line_total', 14, 2);
        });
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_gri_qty CHECK (qty_received >= 0 AND qty_damaged >= 0 AND qty_damaged <= qty_received)');
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_gri_cost CHECK (unit_cost >= 0)');

        Schema::create('purchase_extra_costs', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('grn_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->enum('cost_type', ['freight', 'customs_duty', 'clearing_forwarding', 'local_transport', 'insurance', 'other']);
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('paid_to')->nullable()->comment('empty = billed on the supplier invoice; set = paid to a third party');
            $table->unsignedBigInteger('account_id')->nullable()->comment('financial_accounts.id; the foreign key arrives with Phase 4');
        });
        DB::statement('ALTER TABLE purchase_extra_costs ADD CONSTRAINT chk_pec_amount CHECK (amount >= 0)');

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('return_no', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('grn_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->date('return_date');
            $table->text('reason')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->dateTime('posted_at', 3)->nullable();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 14, 2);
        });
        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT chk_pri_qty CHECK (qty > 0)');

        // Append-only. Suppliers use it now (credit = what we owe); customers use it from Phase 3 on.
        Schema::create('party_ledger_entries', function (Blueprint $table) {
            $table->orbinAppendOnly();
            $table->orbinBranchScoped();
            $table->enum('party_type', ['customer', 'supplier']);
            $table->unsignedBigInteger('party_id');
            $table->date('entry_date')->index();
            $table->enum('entry_type', ['opening', 'invoice', 'payment', 'return', 'discount', 'adjustment', 'writeoff', 'advance']);
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('ref_table', 100)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable()->index();
            $table->string('ref_no', 60)->nullable();
            $table->text('description')->nullable();
            $table->index(['party_type', 'party_id'], 'idx_ple_party');
        });
        DB::statement('ALTER TABLE party_ledger_entries ADD CONSTRAINT chk_ple_sides CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0) <> (credit > 0)))');
    }

    public function down(): void
    {
        foreach (['party_ledger_entries', 'purchase_return_items', 'purchase_returns', 'purchase_extra_costs', 'goods_receipt_items', 'goods_receipts', 'purchase_order_items', 'purchase_orders', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
