<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_order_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id');
            $table->string('account_name');
            $table->uuid('external_order_id');
            $table->string('marketplace_id', 40)->nullable();
            $table->string('external_status', 50);
            $table->string('fulfillment_status', 50)->nullable();
            $table->string('revision')->nullable();
            $table->string('source_hash', 64);
            $table->boolean('invoice_required')->default(false);
            $table->boolean('smart')->default(false);
            $table->string('delivery_method_id')->nullable();
            $table->unsignedInteger('declared_packages')->nullable();
            $table->text('buyer_message')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();
            $table->unique(['environment', 'seller_user_id', 'external_order_id'], 'allegro_orders_seller_external_unique');
        });

        Schema::create('allegro_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_order_link_id')->constrained()->restrictOnDelete();
            $table->string('external_payment_id');
            $table->string('type', 40);
            $table->string('provider', 60)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('accounted_at')->nullable();
            $table->timestamps();
            $table->unique(['allegro_order_link_id', 'external_payment_id'], 'allegro_payments_link_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_order_payments');
        Schema::dropIfExists('allegro_order_links');
    }
};
