<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_series', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 20);
            $table->string('name', 120);
            $table->string('number_format', 120);
            $table->string('reset_period', 20)->default('yearly');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(false);
            $table->foreignId('default_correction_series_id')
                ->nullable()
                ->constrained('invoice_series')
                ->nullOnDelete();
            $table->string('default_currency', 3)->default('PLN');

            $table->string('seller_name')->nullable();
            $table->string('seller_tax_id', 32)->nullable();
            $table->string('seller_regon', 32)->nullable();
            $table->string('seller_bdo', 64)->nullable();
            $table->string('seller_street')->nullable();
            $table->string('seller_building_number', 32)->nullable();
            $table->string('seller_apartment_number', 32)->nullable();
            $table->string('seller_postal_code', 20)->nullable();
            $table->string('seller_city', 120)->nullable();
            $table->string('seller_province', 120)->nullable();
            $table->string('seller_country_code', 2)->default('PL');
            $table->string('seller_email')->nullable();
            $table->string('seller_phone', 64)->nullable();

            $table->string('seller_bank_name')->nullable();
            $table->string('seller_bank_account', 64)->nullable();
            $table->string('seller_bank_swift', 32)->nullable();
            $table->string('place_of_issue', 120)->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('logo_path', 2048)->nullable();
            $table->text('additional_information_template')->nullable();
            $table->timestamps();

            $table->unique(['document_type', 'name']);
            $table->index(['document_type', 'is_active']);
            $table->index(['document_type', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};
