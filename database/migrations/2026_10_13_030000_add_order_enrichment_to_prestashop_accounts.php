<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->string('product_url_template', 2048)->nullable()->after('base_url');
            $table->string('order_enrichment_resource', 64)->nullable()->after('available_resources');
        });
    }

    public function down(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn(['product_url_template', 'order_enrichment_resource']);
        });
    }
};
