<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestashop_order_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestashop_account_id')->nullable()->constrained('prestashop_accounts')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedBigInteger('external_order_id');
            $table->string('external_reference', 64);
            $table->unsignedBigInteger('external_order_state_id')->nullable();
            $table->string('external_order_state_name')->nullable();
            $table->dateTime('external_created_at')->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->char('source_hash', 64);
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique(
                ['prestashop_account_id', 'external_order_id'],
                'prestashop_order_links_account_order_unique',
            );
            $table->unique('order_id');
        });

        Schema::create('prestashop_order_item_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestashop_order_link_id')->constrained('prestashop_order_links')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->unsignedBigInteger('external_order_detail_id');
            $table->unsignedBigInteger('external_product_id')->nullable();
            $table->unsignedBigInteger('external_product_attribute_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['prestashop_order_link_id', 'external_order_detail_id'],
                'prestashop_order_item_links_detail_unique',
            );
            $table->unique('order_item_id');
        });

        Schema::create('prestashop_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestashop_order_link_id')->constrained('prestashop_order_links')->cascadeOnDelete();
            $table->unsignedBigInteger('external_payment_id');
            $table->string('transaction_id', 191)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('method')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['prestashop_order_link_id', 'external_payment_id'],
                'prestashop_order_payments_external_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestashop_order_payments');
        Schema::dropIfExists('prestashop_order_item_links');
        Schema::dropIfExists('prestashop_order_links');
    }
};
