<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('code', 191)->unique()->comment('SKU prefix');
            $table->string('name_en', 191)->index();
            $table->string('name_bn', 191)->nullable()->index();
            $table->enum('mirror_type', ['side_mirror', 'interior_mirror', 'mirror_glass', 'mirror_cover', 'mirror_signal_lamp', 'mirror_motor', 'blind_spot_mirror', 'accessory'])->index();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('product_brands')->nullOnDelete();
            $table->foreignId('quality_grade_id')->constrained('lookup_values')->restrictOnDelete();
            $table->foreignId('origin_country_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();
            $table->integer('warranty_months')->default(0);
            $table->integer('return_window_days')->nullable();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->json('tags')->nullable();
            $table->string('primary_image_url')->nullable();
            $table->json('custom')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('search_text')->nullable()->comment('lowercase name_en, name_bn, code, tags, brand; system maintained');
            $table->index(['is_active', 'mirror_type']);
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_warranty CHECK (warranty_months >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
