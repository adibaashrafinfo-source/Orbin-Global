<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_defs', function (Blueprint $table) {
            $table->orbinCommon();
            $table->enum('entity', ['product', 'variant', 'customer', 'supplier'])->index();
            $table->string('key', 191);
            $table->string('label_en');
            $table->string('label_bn')->nullable();
            $table->enum('field_type', ['text', 'long_text', 'number', 'date', 'select', 'multi_select', 'boolean', 'url']);
            $table->json('options')->nullable()->comment('for select/multi_select');
            $table->boolean('is_required')->default(false);
            $table->boolean('show_on_pos_card')->default(false);
            $table->boolean('show_on_invoice')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unique(['entity', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_defs');
    }
};
