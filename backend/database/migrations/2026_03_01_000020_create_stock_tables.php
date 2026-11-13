<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maintained only by StockPostingService, in the same transaction as the movement.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('storage_locations')->nullOnDelete()->comment('informational primary bin');
            $table->decimal('qty_on_hand', 14, 3)->default(0);
            $table->decimal('qty_reserved', 14, 3)->default(0);
            $table->decimal('avg_cost', 14, 4)->default(0);
            $table->dateTime('last_movement_at', 3)->nullable();
            $table->unique(['variant_id', 'warehouse_id'], 'uq_stock_variant_warehouse');
        });

        // Append-only source of truth: stock_levels.qty_on_hand = sum(qty_delta).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->orbinAppendOnly();
            $table->orbinBranchScoped();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('movement_type', ['opening', 'purchase_receipt', 'purchase_return', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'transfer_out', 'transfer_in', 'breakage', 'damage', 'count_correction'])->index();
            $table->decimal('qty_delta', 14, 3);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('balance_after', 14, 3);
            $table->string('ref_table', 100)->nullable()->index();
            $table->unsignedBigInteger('ref_id')->nullable()->index();
            $table->string('ref_no', 60)->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('occurred_at', 3)->useCurrent()->index();
            $table->index(['variant_id', 'warehouse_id', 'occurred_at'], 'idx_sm_card');
        });
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_sm_delta CHECK (qty_delta <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
    }
};
