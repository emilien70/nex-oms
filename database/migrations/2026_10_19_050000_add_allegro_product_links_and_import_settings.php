<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allegro_product_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allegro_account_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id');
            $table->string('external_offer_id', 32);
            $table->timestamps();
            $table->unique(['allegro_account_id', 'environment', 'seller_user_id', 'external_offer_id'], 'allegro_product_links_offer_unique');
            $table->index(['product_id', 'allegro_account_id']);
        });

        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->boolean('use_linked_products')->default(true);
            $table->boolean('use_catalog_product_names')->default(true);
            $table->boolean('use_catalog_vat')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_product_links');
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->dropColumn(['use_linked_products', 'use_catalog_product_names', 'use_catalog_vat']);
        });
    }
};
