<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('adj_no', 40)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'rejected'])->default('draft')->index();
            $table->foreignId('reason_id')->constrained('lookup_values')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->dateTime('posted_at', 3)->nullable();
            $table->decimal('total_value', 14, 2)->default(0);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_delta', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->boolean('is_breakage')->default(false);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete()->comment('for a supplier claim');
            $table->foreignId('photo_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->text('note')->nullable();
        });
        DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT chk_sai_delta CHECK (qty_delta <> 0)');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('transfer_no', 40)->unique();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('status', ['draft', 'in_transit', 'received', 'cancelled'])->default('draft')->index();
            $table->dateTime('sent_at', 3)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at', 3)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
        });
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT chk_st_wh CHECK (from_warehouse_id <> to_warehouse_id)');

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_sent', 14, 3);
            $table->decimal('qty_received', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->text('note')->nullable();
        });
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_sti_qty CHECK (qty_sent > 0)');

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('count_no', 40)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('scope', ['full', 'category', 'location'])->default('full');
            $table->unsignedBigInteger('scope_ref')->nullable()->comment('category_id or location_id');
            $table->boolean('is_blind')->default(false);
            $table->enum('status', ['draft', 'counting', 'review', 'posted', 'cancelled'])->default('draft')->index();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('posted_at', 3)->nullable();
            $table->text('note')->nullable();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('system_qty', 14, 3)->comment('snapshot at count start');
            $table->decimal('counted_qty', 14, 3)->nullable();
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->text('note')->nullable();
            $table->unique(['count_id', 'variant_id'], 'uq_sci_count_variant');
        });
    }

    public function down(): void
    {
        foreach (['stock_count_items', 'stock_counts', 'stock_transfer_items', 'stock_transfers', 'stock_adjustment_items', 'stock_adjustments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
