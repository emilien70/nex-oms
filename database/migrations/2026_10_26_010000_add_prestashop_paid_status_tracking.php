<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->boolean('warn_missing_transaction_id')->default(false);
        });

        Schema::table('prestashop_order_links', function (Blueprint $table): void {
            $table->boolean('external_payment_paid')->nullable();
            $table->decimal('status_paid_amount', 14, 2)->default(0);
            $table->timestamp('status_paid_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('prestashop_order_links')->whereNotNull('status_paid_at')->exists()) {
            throw new RuntimeException('Nie mozna usunac historii oplacenia wedlug statusu PrestaShop.');
        }

        Schema::table('prestashop_order_links', function (Blueprint $table): void {
            $table->dropColumn(['external_payment_paid', 'status_paid_amount', 'status_paid_at']);
        });

        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn('warn_missing_transaction_id');
        });
    }
};
