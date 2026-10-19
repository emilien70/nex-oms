<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->boolean('order_import_enabled')
                ->default(false)
                ->after('last_status_sync_error');
            $table->timestamp('order_import_started_at')->nullable()->after('order_import_enabled');
            $table->timestamp('last_order_import_scan_at')->nullable()->after('order_import_started_at');
            $table->timestamp('last_order_import_error_at')->nullable()->after('last_order_import_scan_at');
            $table->string('last_order_import_error', 1000)->nullable()->after('last_order_import_error_at');
        });

        Schema::create('prestashop_order_import_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prestashop_account_id')
                ->nullable()
                ->constrained('prestashop_accounts')
                ->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedBigInteger('external_order_id');
            $table->string('external_reference', 64)->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('discovered_at');
            $table->dateTime('external_created_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->unique(
                ['prestashop_account_id', 'external_order_id'],
                'prestashop_order_import_attempts_account_order_unique',
            );
            $table->index(
                ['prestashop_account_id', 'status'],
                'prestashop_order_import_attempts_account_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestashop_order_import_attempts');

        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'order_import_enabled',
                'order_import_started_at',
                'last_order_import_scan_at',
                'last_order_import_error_at',
                'last_order_import_error',
            ]);
        });
    }
};
