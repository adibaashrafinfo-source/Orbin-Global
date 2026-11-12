<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('url');
            $table->string('thumb_url')->nullable();
            $table->string('label', 20)->default('other');
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
        });
        DB::statement("ALTER TABLE product_images ADD CONSTRAINT chk_pi_label CHECK (label IN ('front','back','connector','package','size-chart','other'))");

        Schema::create('oem_cross_references', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->enum('ref_type', ['oem', 'aftermarket', 'competitor', 'supplier'])->default('oem');
            $table->string('number');
            $table->string('number_normalized', 191)->index()->comment('uppercase, no spaces or dashes');
            $table->string('brand')->nullable();
            $table->text('note')->nullable();
            $table->unique(['variant_id', 'ref_type', 'number_normalized'], 'uq_oem_variant_type_number');
        });

        Schema::create('vehicle_fitments', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->integer('year_from');
            $table->integer('year_to')->nullable()->comment('null = open ended');
            $table->string('trim')->nullable();
            $table->text('note')->nullable();
            $table->string('signature', 191)->unique()->comment('sha1 of variant, model, years, trim; prevents duplicates despite NULLs');
            $table->index(['vehicle_model_id', 'year_from', 'year_to'], 'idx_fitment_model_years');
        });
        DB::statement('ALTER TABLE vehicle_fitments ADD CONSTRAINT chk_vf_years CHECK (year_to IS NULL OR year_to >= year_from)');

        Schema::create('variant_bundle_items', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('bundle_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 14, 3)->default(1);
            $table->unique(['bundle_variant_id', 'component_variant_id'], 'uq_bundle_component');
        });
        DB::statement('ALTER TABLE variant_bundle_items ADD CONSTRAINT chk_vbi_qty CHECK (qty > 0)');
        DB::statement('ALTER TABLE variant_bundle_items ADD CONSTRAINT chk_vbi_self CHECK (bundle_variant_id <> component_variant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_bundle_items');
        Schema::dropIfExists('vehicle_fitments');
        Schema::dropIfExists('oem_cross_references');
        Schema::dropIfExists('product_images');
    }
};
