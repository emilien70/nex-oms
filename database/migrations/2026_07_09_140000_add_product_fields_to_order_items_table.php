<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'currency')) {
                $table->string('currency', 10)->nullable()->after('total_price_gross');
            }

            if (! Schema::hasColumn('order_items', 'vat_rate')) {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('order_items', 'weight')) {
                $table->decimal('weight', 10, 3)->nullable()->after('vat_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'weight')) {
                $table->dropColumn('weight');
            }

            if (Schema::hasColumn('order_items', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }

            if (Schema::hasColumn('order_items', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
