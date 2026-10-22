<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('item_type', 20)
                ->default('product')
                ->after('id')
                ->index();
        });

        Schema::create('product_bundle_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('component_product_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['bundle_id', 'component_product_id']);
            $table->index('component_product_id');
        });

        Schema::create('order_item_bundle_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->cascadeOnDelete();
            $table->foreignId('bundle_product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->foreignId('component_product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->string('component_product_name', 255);
            $table->string('component_ean', 14)->nullable();
            $table->unsignedInteger('quantity_per_bundle');
            $table->timestamps();

            $table->unique(['order_item_id', 'component_product_id'], 'order_bundle_item_component_uq');
            $table->index(['bundle_product_id', 'order_item_id'], 'order_bundle_product_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_bundle_components');
        Schema::dropIfExists('product_bundle_components');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['item_type']);
            $table->dropColumn('item_type');
        });
    }
};
