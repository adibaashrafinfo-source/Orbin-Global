<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // users per spec dataModel.users (+ framework tables password_reset_tokens and sessions).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email', 191)->unique();
            $table->string('password_hash');
            $table->enum('role', ['super_admin', 'manager', 'cashier', 'storekeeper', 'accountant'])->index();
            $table->unsignedBigInteger('default_branch_id')->nullable(); // FK added in branches migration
            $table->json('branch_ids')->nullable();
            $table->string('override_pin_hash')->nullable();
            $table->integer('pin_failed_attempts')->default(0);
            $table->dateTime('pin_locked_until', 3)->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('language', 2)->default('en');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->string('remember_token', 100)->nullable();
            $table->dateTime('last_login_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('deleted_at', 3)->nullable()->index();
        });

        // check: language in ('en','bn') — expressed as a CHECK constraint (MySQL 8.0.16+/MariaDB 10.2+).
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_language CHECK (language IN ('en','bn'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
