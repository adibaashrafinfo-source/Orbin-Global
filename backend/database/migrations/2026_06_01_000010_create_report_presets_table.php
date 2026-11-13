<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved filter presets, one set per user per report (FR-1100 "saved presets"). The JSON data model has no table for them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_presets', function (Blueprint $table) {
            $table->id();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_id', 60);
            $table->string('name', 80);
            $table->json('filters');
            $table->unique(['user_id', 'report_id', 'name'], 'uniq_rp_user_report_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_presets');
    }
};
