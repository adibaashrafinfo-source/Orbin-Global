<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('entity_table', 191)->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('storage_bucket');
            $table->string('storage_path');
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
