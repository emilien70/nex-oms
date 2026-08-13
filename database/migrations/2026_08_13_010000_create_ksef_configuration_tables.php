<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 32)->default('default')->unique();
            $table->string('name', 120)->default('KSeF');
            $table->string('environment', 20)->default('test');
            $table->string('context_nip', 10)->nullable();
            $table->boolean('automatic_submission')->default(false);
            $table->boolean('send_without_buyer_nip')->default(false);
            $table->boolean('include_recipient_data')->default(false);
            $table->boolean('include_buyer_contact_data')->default(false);
            $table->boolean('include_additional_information')->default(false);
            $table->boolean('include_order_reference')->default(true);
            $table->boolean('include_bank_account')->default(true);
            $table->boolean('include_gtu')->default(true);
            $table->boolean('include_sale_date')->default(true);
            $table->timestamps();
        });

        Schema::create('ksef_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->string('authentication_method', 20)->default('token');
            $table->text('api_token')->nullable();
            $table->timestamps();
        });

        Schema::create('ksef_series_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_series_id')
                ->unique()
                ->constrained('invoice_series')
                ->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_series_settings');
        Schema::dropIfExists('ksef_credentials');
        Schema::dropIfExists('ksef_settings');
    }
};
