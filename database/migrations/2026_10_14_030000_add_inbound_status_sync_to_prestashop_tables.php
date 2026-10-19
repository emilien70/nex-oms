<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->boolean('status_sync_enabled')
                ->default(false)
                ->after('notify_customer_on_status_change');
            $table->unsignedTinyInteger('status_sync_interval_minutes')
                ->default(1)
                ->after('status_sync_enabled');
            $table->timestamp('last_status_sync_at')->nullable()->after('status_sync_interval_minutes');
            $table->timestamp('last_status_sync_error_at')->nullable()->after('last_status_sync_at');
            $table->string('last_status_sync_error', 1000)->nullable()->after('last_status_sync_error_at');
        });

        Schema::table('prestashop_order_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('pending_outbound_state_id')
                ->nullable()
                ->after('external_order_state_name');
            $table->uuid('pending_outbound_event_id')
                ->nullable()
                ->after('pending_outbound_state_id');
            $table->timestamp('pending_outbound_since')
                ->nullable()
                ->after('pending_outbound_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('prestashop_order_links', function (Blueprint $table): void {
            $table->dropColumn([
                'pending_outbound_state_id',
                'pending_outbound_event_id',
                'pending_outbound_since',
            ]);
        });

        Schema::table('prestashop_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'status_sync_enabled',
                'status_sync_interval_minutes',
                'last_status_sync_at',
                'last_status_sync_error_at',
                'last_status_sync_error',
            ]);
        });
    }
};
