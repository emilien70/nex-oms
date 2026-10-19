<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['currency', 'purchased_at'], 'orders_analytics_currency_purchased_idx');
            $table->index(['currency', 'created_at'], 'orders_analytics_currency_created_idx');
            $table->index(['status', 'purchased_at'], 'orders_analytics_status_purchased_idx');
            $table->index(['source', 'purchased_at'], 'orders_analytics_source_purchased_idx');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['order_id', 'product_id'], 'order_items_analytics_order_product_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_analytics_order_product_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_analytics_currency_purchased_idx');
            $table->dropIndex('orders_analytics_currency_created_idx');
            $table->dropIndex('orders_analytics_status_purchased_idx');
            $table->dropIndex('orders_analytics_source_purchased_idx');
        });
    }
};
