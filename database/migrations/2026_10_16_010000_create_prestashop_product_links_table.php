<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestashop_product_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('prestashop_account_id')->constrained('prestashop_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('external_product_id');
            $table->unsignedBigInteger('external_product_attribute_id')->default(0);
            $table->timestamps();

            $table->unique(
                ['product_id', 'prestashop_account_id'],
                'prestashop_product_links_product_account_unique',
            );
            $table->unique(
                ['prestashop_account_id', 'external_product_id', 'external_product_attribute_id'],
                'prestashop_product_links_external_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestashop_product_links');
    }
};
