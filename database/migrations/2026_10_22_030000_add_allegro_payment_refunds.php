<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->boolean('refunds_enabled')->default(false);
            $table->timestamp('refunds_from_at')->nullable();
            $table->string('refunds_identity')->nullable();
            $table->unsignedBigInteger('refunds_revision')->default(0);
            $table->timestamp('refunds_scan_until')->nullable();
            $table->unsignedInteger('refunds_scan_offset')->default(0);
            $table->timestamp('refunds_last_scan_at')->nullable();
            $table->timestamp('refunds_retry_after')->nullable();
            $table->text('refunds_error')->nullable();
        });
        Schema::create('allegro_payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id', 100);
            $table->uuid('external_refund_id');
            $table->uuid('external_payment_id');
            $table->uuid('external_order_id')->nullable();
            $table->string('account_name');
            $table->string('status', 100);
            $table->string('reason', 100);
            $table->char('currency', 3);
            $table->decimal('total_value', 18, 2);
            $table->decimal('products_value', 18, 2)->nullable();
            $table->decimal('delivery_value', 18, 2);
            $table->decimal('other_value', 18, 2);
            $table->json('components');
            $table->string('seller_comment', 250)->nullable();
            $table->timestamp('requested_at', 6)->index();
            $table->string('source_hash', 64);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('synced_at');
            $table->timestamp('next_sync_at')->index();
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'seller_user_id', 'external_refund_id'], 'allegro_refunds_identity_unique');
            $table->index(['environment', 'seller_user_id', 'external_order_id'], 'allegro_refunds_order_identity');
            $table->index('order_id');
        });
        Schema::create('allegro_payment_refund_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_payment_refund_id')->constrained()->restrictOnDelete();
            $table->json('data');
            $table->timestamp('observed_at');
        });
        Schema::create('order_return_refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_payment_refund_id')->constrained(indexName: 'return_refund_allocation_refund_fk')->restrictOnDelete();
            $table->foreignId('order_return_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('return_number');
            $table->decimal('products_value', 18, 2);
            $table->decimal('delivery_value', 18, 2);
            $table->char('currency', 3);
            $table->timestamps();
            $table->unique(['allegro_payment_refund_id', 'order_return_id'], 'return_refund_allocation_unique');
            $table->index('order_return_id');
        });
        Schema::table('order_return_events', fn (Blueprint $table) => $table->json('data')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Rejestr refundacji zawiera dane historyczne. Cofniecie wymaga osobnej migracji danych.');
    }
};
