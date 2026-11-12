<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('name', 191)->unique();
            $table->string('logo_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('make_id')->constrained('vehicle_makes')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('generation_code', 100)->default('')->comment('e.g. NZE141; empty string when unknown');
            $table->foreignId('body_type_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->integer('year_from')->nullable();
            $table->integer('year_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['make_id', 'name', 'generation_code']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_makes');
    }
};
