<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['allegro_accounts', 'prestashop_accounts'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->decimal('default_vat_rate', 5, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['allegro_accounts', 'prestashop_accounts'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('default_vat_rate');
            });
        }
    }
};
