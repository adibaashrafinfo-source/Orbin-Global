<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single row guaranteed by the unique `singleton` column (always 1).
        Schema::create('company_profile', function (Blueprint $table) {
            $table->orbinCommon();
            $table->integer('singleton')->default(1)->unique();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('tagline')->nullable()->default('Trust. Growth. Excellence.');
            $table->string('logo_url')->nullable();
            $table->string('logo_storage_path')->nullable();
            $table->text('address_line1');
            $table->text('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->nullable()->default('Bangladesh');
            $table->json('phones');
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('bin_no')->nullable();
            $table->string('tin_no')->nullable();
            $table->string('trade_license_no')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('signature_url')->nullable();
            $table->text('invoice_terms')->nullable();
            $table->text('return_policy_text')->nullable();
            $table->text('warranty_policy_text')->nullable();
            $table->text('footer_note')->nullable();
            $table->string('invoice_primary_color', 9)->nullable()->default('#002756');
            $table->string('invoice_accent_color', 9)->nullable()->default('#FF7900');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profile');
    }
};
