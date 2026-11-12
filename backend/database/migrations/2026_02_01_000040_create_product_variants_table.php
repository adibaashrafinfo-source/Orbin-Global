<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 191)->unique();
            $table->string('barcode', 191)->nullable()->unique();
            $table->json('barcode_aliases')->nullable();
            $table->enum('position', ['left', 'right', 'interior', 'pair', 'universal'])->index();
            $table->decimal('size_width_mm', 7, 1)->nullable();
            $table->decimal('size_height_mm', 7, 1)->nullable();
            $table->decimal('thickness_mm', 5, 1)->nullable();
            $table->string('size_label', 191)->nullable()->index();
            $table->decimal('weight_g', 8, 1)->nullable();
            $table->foreignId('glass_type_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->foreignId('tint_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->boolean('is_glass_only')->default(false);
            $table->foreignId('color_finish_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->boolean('cover_included')->default(false);
            $table->enum('adjustment', ['manual', 'electric', 'cable'])->nullable();
            $table->boolean('heated')->default(false);
            $table->boolean('auto_fold')->default(false);
            $table->boolean('turn_signal')->default(false);
            $table->boolean('blind_spot_sensor')->default(false);
            $table->boolean('side_camera')->default(false);
            $table->boolean('memory_tilt')->default(false);
            $table->boolean('auto_dimming')->default(false);
            $table->boolean('puddle_light')->default(false);
            $table->integer('pin_count')->nullable();
            $table->text('connector_note')->nullable();
            $table->string('supplier_part_no')->nullable();
            $table->integer('year_from')->nullable();
            $table->integer('year_to')->nullable();
            $table->decimal('last_cost', 14, 2)->default(0)->comment('system maintained');
            $table->decimal('retail_price', 14, 2);
            $table->decimal('wholesale_price', 14, 2)->nullable();
            $table->decimal('dealer_price', 14, 2)->nullable();
            $table->decimal('mrp', 14, 2)->nullable();
            $table->decimal('min_price', 14, 2)->nullable()->comment('floor price (BR-020)');
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('reorder_qty', 14, 3)->default(0);
            $table->decimal('max_stock', 14, 3)->nullable();
            $table->foreignId('default_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->boolean('track_serial')->default(false)->comment('phase 2');
            $table->boolean('is_bundle')->default(false);
            $table->boolean('is_discontinued')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('internal_note')->nullable();
            $table->json('custom')->nullable();
            $table->string('variant_name', 255)->nullable()->comment('generated display name');
            $table->string('signature', 191)->unique()->comment('sha1 uniqueness key (BR-092); id appended on soft delete');
            $table->text('search_text')->nullable()->comment('lowercase names, sku, barcode, variant_name, oem numbers, tags, brand, size_label');
            $table->index(['product_id', 'position']);
            $table->index(['is_active', 'is_discontinued']);
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_pv_retail CHECK (retail_price >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_pv_wholesale CHECK (wholesale_price IS NULL OR wholesale_price >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_pv_dealer CHECK (dealer_price IS NULL OR dealer_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
