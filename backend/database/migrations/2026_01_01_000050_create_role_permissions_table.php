<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->orbinCommon();
            $table->enum('role', ['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant'])->index();
            $table->string('permission_key', 191);
            $table->boolean('allowed')->default(false);
            $table->unique(['role', 'permission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
