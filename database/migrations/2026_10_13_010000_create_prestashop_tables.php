<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestashop_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 60)->unique();
            $table->string('name', 120);
            $table->string('base_url', 2048);
            $table->text('api_key')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('shop_name')->nullable();
            $table->unsignedBigInteger('language_id')->nullable();
            $table->string('language_iso_code', 10)->nullable();
            $table->json('available_languages')->nullable();
            $table->json('available_currencies')->nullable();
            $table->json('available_resources')->nullable();
            $table->string('detected_version', 40)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('prestashop_order_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestashop_account_id')->constrained('prestashop_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('external_id');
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->boolean('unremovable')->default(false);
            $table->boolean('delivery')->default(false);
            $table->boolean('hidden')->default(false);
            $table->boolean('send_email')->default(false);
            $table->boolean('invoice')->default(false);
            $table->boolean('logable')->default(false);
            $table->boolean('shipped')->default(false);
            $table->boolean('paid')->default(false);
            $table->boolean('deleted')->default(false);
            $table->timestamp('refreshed_at');
            $table->timestamps();

            $table->unique(['prestashop_account_id', 'external_id'], 'prestashop_order_states_account_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestashop_order_states');
        Schema::dropIfExists('prestashop_accounts');
    }
};
