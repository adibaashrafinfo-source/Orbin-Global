<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: never updated or deleted (enforced in code, see App\Models\AuditLog).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->orbinAppendOnly();
            $table->dateTime('occurred_at', 3)->useCurrent()->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->comment('create, update, delete, void, price_change, stock_adjust, override, permission_change, login, export');
            $table->string('entity_table', 191)->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
