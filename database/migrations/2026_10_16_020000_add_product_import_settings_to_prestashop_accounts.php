<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->boolean('use_linked_products')->default(true)->after('order_import_enabled');
            $table->boolean('use_catalog_product_names')->default(true)->after('use_linked_products');
            $table->string('catalog_vat_mode', 20)->default('fallback')->after('use_catalog_product_names');
        });
    }

    public function down(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'use_linked_products',
                'use_catalog_product_names',
                'catalog_vat_mode',
            ]);
        });
    }
};
