<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->boolean('returns_enabled')->default(false);
            $table->timestamp('returns_from_at')->nullable();
            $table->string('returns_identity')->nullable();
            $table->unsignedBigInteger('returns_revision')->default(0);
            $table->unsignedInteger('returns_scan_offset')->default(0);
            $table->timestamp('returns_scan_until')->nullable();
            $table->timestamp('returns_last_scan_at')->nullable();
            $table->text('returns_error')->nullable();
        });
        Schema::create('order_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 30)->default('allegro');
            $table->foreignId('allegro_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id', 100);
            $table->uuid('external_return_id');
            $table->uuid('external_order_id');
            $table->string('reference_number');
            $table->string('account_name');
            $table->string('buyer_login')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->string('marketplace_id', 50)->nullable();
            $table->string('status', 100);
            $table->char('currency', 3);
            $table->decimal('total_value', 18, 2);
            $table->json('order_snapshot')->nullable();
            $table->string('source_hash', 64);
            $table->timestamp('submitted_at')->index();
            $table->timestamp('status_observed_at');
            $table->timestamp('synced_at');
            $table->timestamp('next_sync_at')->index();
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'seller_user_id', 'external_return_id'], 'order_returns_source_unique');
            $table->index(['environment', 'seller_user_id', 'external_order_id'], 'order_returns_order_identity');
            $table->index('status');
        });
        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('offer_id', 100);
            $table->text('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->decimal('total_value', 18, 2);
            $table->char('currency', 3);
            $table->string('reason', 100);
            $table->text('comment')->nullable();
            $table->unique(['order_return_id', 'position']);
        });
        Schema::create('order_return_parcels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('waybill')->nullable()->index();
            $table->string('carrier_id')->nullable();
            $table->string('transporting_waybill')->nullable()->index();
            $table->string('transporting_carrier_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unique(['order_return_id', 'position']);
        });
        Schema::create('order_return_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('previous_status', 100)->nullable();
            $table->string('status', 100);
            $table->timestamp('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_events');
        Schema::dropIfExists('order_return_parcels');
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->dropColumn(['returns_enabled', 'returns_from_at', 'returns_identity', 'returns_revision',
                'returns_scan_offset', 'returns_scan_until', 'returns_last_scan_at', 'returns_error']);
        });
    }
};
