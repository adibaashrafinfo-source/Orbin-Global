<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and the financial accounts that sales post into. Account management screens arrive in Phase 4;
 * the tables are needed now because every payment line posts an account transaction (FR-805).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->orbinCommon();
            $table->string('code', 40)->unique();
            $table->foreignId('customer_type_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->enum('price_tier', ['retail', 'wholesale', 'dealer'])->default('retail');
            $table->string('name', 200)->index();
            $table->string('company_name', 200)->nullable()->index();
            $table->json('phones');
            $table->string('whatsapp', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('area', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('nid_no', 40)->nullable();
            $table->string('trade_license_no', 60)->nullable();
            $table->string('bin_no', 40)->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->json('custom')->nullable();
            $table->boolean('is_walk_in')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('phone_primary', 20)->nullable()->index();
            $table->unsignedTinyInteger('walkin_key')->nullable()->unique()->comment('1 only for the walk-in customer, otherwise NULL');
            $table->text('search_text')->nullable();
        });
        DB::statement('ALTER TABLE customers ADD CONSTRAINT chk_customers_money CHECK (credit_limit >= 0 AND opening_balance >= 0)');

        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->orbinCommon();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('reg_no', 40);
            $table->foreignId('make_id')->nullable()->constrained('vehicle_makes')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 40)->nullable();
            $table->string('note', 255)->nullable();
            $table->unique(['customer_id', 'reg_no']);
        });

        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->orbinCommon();
            $table->orbinBranchScoped();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->enum('account_type', ['cash', 'bkash', 'nagad', 'rocket', 'bank'])->index();
            $table->string('provider', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_date');
            $table->boolean('is_default_for_method')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->orbinAppendOnly();
            $table->orbinBranchScoped();
            $table->foreignId('account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 14, 2);
            $table->date('txn_date')->index();
            $table->dateTime('occurred_at', 3)->useCurrent();
            $table->enum('ref_type', ['opening', 'sale_payment', 'sale_refund', 'party_payment', 'expense', 'fund_transfer', 'shift_cash_movement', 'adjustment'])->index();
            $table->unsignedBigInteger('ref_id')->nullable()->index();
            $table->string('ref_no', 60)->nullable();
            $table->enum('counterparty_type', ['customer', 'supplier'])->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->text('description')->nullable();
            $table->index(['account_id', 'txn_date'], 'idx_at_account_date');
        });
        DB::statement('ALTER TABLE account_transactions ADD CONSTRAINT chk_at_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        foreach (['account_transactions', 'financial_accounts', 'customer_vehicles', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
