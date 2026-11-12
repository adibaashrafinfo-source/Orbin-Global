<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_brands', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('name', 191)->unique();
            $table->foreignId('country_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->string('logo_url')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_oem')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name_en');
            $table->string('name_bn')->nullable();
            $table->string('slug', 191)->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('code', 191)->unique();
            $table->string('name_en');
            $table->string('name_bn')->nullable();
            $table->boolean('is_pair_unit')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('code', 191)->unique();
            $table->string('name');
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
        });
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT chk_tax_rate_percent CHECK (rate_percent >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_brands');
    }
};
