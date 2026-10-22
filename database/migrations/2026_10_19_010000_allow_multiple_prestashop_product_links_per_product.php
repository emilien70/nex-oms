<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL must have the replacement index before dropping the index supporting the foreign key.
        Schema::table('prestashop_product_links', function (Blueprint $table): void {
            $table->index(
                ['product_id', 'prestashop_account_id'],
                'prestashop_product_links_product_account_index',
            );
        });

        Schema::table('prestashop_product_links', function (Blueprint $table): void {
            $table->dropUnique('prestashop_product_links_product_account_unique');
        });
    }

    public function down(): void
    {
        $hasMultipleLinks = DB::table('prestashop_product_links')
            ->select(['product_id', 'prestashop_account_id'])
            ->groupBy('product_id', 'prestashop_account_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleLinks) {
            throw new RuntimeException(
                'Nie można przywrócić pojedynczego powiązania bez usunięcia istniejących danych.',
            );
        }

        Schema::table('prestashop_product_links', function (Blueprint $table): void {
            $table->unique(
                ['product_id', 'prestashop_account_id'],
                'prestashop_product_links_product_account_unique',
            );
        });

        Schema::table('prestashop_product_links', function (Blueprint $table): void {
            $table->dropIndex('prestashop_product_links_product_account_index');
        });
    }
};
