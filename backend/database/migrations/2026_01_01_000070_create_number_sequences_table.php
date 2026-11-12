<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('key', 191);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('prefix');
            $table->string('date_token')->nullable()->comment('e.g. YYMM');
            $table->integer('padding')->default(6);
            $table->enum('reset_policy', ['never', 'yearly', 'monthly'])->default('yearly');
            $table->string('period_key', 191)->default('all');
            $table->bigInteger('next_value')->default(1);
            $table->unique(['key', 'branch_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
