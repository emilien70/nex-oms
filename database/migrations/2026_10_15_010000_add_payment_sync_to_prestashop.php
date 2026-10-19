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
            $table->boolean('payment_sync_enabled')->default(false)->after('last_order_import_error');
            $table->dateTime('last_payment_sync_at')->nullable()->after('payment_sync_enabled');
            $table->dateTime('last_payment_sync_error_at')->nullable()->after('last_payment_sync_at');
            $table->text('last_payment_sync_error')->nullable()->after('last_payment_sync_error_at');
        });

        Schema::table('prestashop_order_payments', function (Blueprint $table): void {
            $table->string('accounting_status', 24)->default('accounted')->after('paid_at');
            $table->dateTime('accounted_at')->nullable()->after('accounting_status');
            $table->boolean('needs_review')->default(false)->after('accounted_at');
            $table->string('safe_error_code', 64)->nullable()->after('needs_review');
            $table->text('safe_error_message')->nullable()->after('safe_error_code');
            $table->string('matched_payment_source', 32)->nullable()->after('safe_error_message');
            $table->unsignedBigInteger('matched_payment_record_id')->nullable()->after('matched_payment_source');
            $table->index('accounting_status', 'prestashop_order_payments_accounting_status_index');
        });

        DB::table('prestashop_order_payments')
            ->whereNull('accounted_at')
            ->update([
                'accounting_status' => 'accounted',
                'accounted_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('prestashop_order_payments', function (Blueprint $table): void {
            $table->dropIndex('prestashop_order_payments_accounting_status_index');
            $table->dropColumn([
                'accounting_status',
                'accounted_at',
                'needs_review',
                'safe_error_code',
                'safe_error_message',
                'matched_payment_source',
                'matched_payment_record_id',
            ]);
        });

        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_sync_enabled',
                'last_payment_sync_at',
                'last_payment_sync_error_at',
                'last_payment_sync_error',
            ]);
        });
    }
};
