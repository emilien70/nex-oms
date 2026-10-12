<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paynow_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 60)->unique();
            $table->string('name', 120);
            $table->string('environment', 20)->default('sandbox');
            $table->text('api_key')->nullable();
            $table->text('signature_key')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('link_validity_seconds')->default(604800);
            $table->string('description_template', 100)->default('Płatność za zamówienie nr [id_zamowienia]');
            $table->string('continue_url', 2048)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('paynow_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paynow_account_id')->nullable()->constrained('paynow_accounts')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->string('external_id', 100)->unique();
            $table->string('provider_payment_id', 64)->nullable()->unique();
            $table->string('idempotency_key', 45)->unique();
            $table->text('payment_url')->nullable();
            $table->string('status', 20)->default('NEW')->index();
            $table->string('buyer_email_snapshot', 254);
            $table->string('description', 100);
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('provider_modified_at')->nullable();
            $table->timestamp('last_status_checked_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('needs_review')->default(false)->index();
            $table->string('safe_error_code', 100)->nullable();
            $table->string('safe_error_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['paynow_account_id', 'created_at']);
        });

        Schema::create('paynow_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paynow_payment_id')->nullable()->constrained('paynow_payments')->nullOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('source', 30);
            $table->string('provider_payment_id', 64);
            $table->string('external_id', 100);
            $table->string('status', 20)->index();
            $table->timestamp('provider_modified_at')->nullable();
            $table->timestamp('received_at');
            $table->longText('payload')->nullable();
            $table->char('payload_sha256', 64);
            $table->timestamps();

            $table->index(['provider_payment_id', 'received_at']);
            $table->index(['external_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paynow_payment_events');
        Schema::dropIfExists('paynow_payments');
        Schema::dropIfExists('paynow_accounts');
    }
};
