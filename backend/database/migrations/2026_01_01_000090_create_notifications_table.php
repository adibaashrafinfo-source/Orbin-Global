<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant'])->nullable();
            $table->string('type')->comment('low_stock, approval_pending, overdue_due, shift_open, payment_pending');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('entity_table')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('read_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
