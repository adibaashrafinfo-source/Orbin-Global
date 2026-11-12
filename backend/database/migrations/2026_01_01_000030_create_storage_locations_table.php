<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->string('code', 191);
            $table->string('name')->nullable();
            $table->enum('kind', ['rack', 'shelf', 'bin'])->default('rack');
            $table->boolean('is_active')->default(true);
            $table->unique(['warehouse_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
