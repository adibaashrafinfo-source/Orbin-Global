<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_types', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('key', 191)->unique();
            $table->string('name_en');
            $table->string('name_bn')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('lookup_values', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('type_id')->constrained('lookup_types')->restrictOnDelete();
            $table->string('code', 191);
            $table->string('label_en');
            $table->string('label_bn')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['type_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_values');
        Schema::dropIfExists('lookup_types');
    }
};
