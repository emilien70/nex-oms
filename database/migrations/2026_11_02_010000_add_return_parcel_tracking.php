<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_return_parcels', function (Blueprint $table): void {
            $table->boolean('is_manual')->default(false);
            $table->char('tracking_identity', 64)->nullable();
            $table->string('tracking_status', 40)->nullable();
            $table->string('provider_status', 100)->nullable();
            $table->dateTime('tracking_status_at', 6)->nullable();
            $table->dateTime('tracking_started_at', 6)->nullable();
            $table->dateTime('tracking_checked_at', 6)->nullable();
            $table->dateTime('tracking_next_sync_at', 6)->nullable()->index('orp_tracking_due_index');
            $table->string('tracking_error', 500)->nullable();
        });
        Schema::create('order_return_parcel_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_return_id');
            $table->foreign('order_return_id', 'orpe_return_fk')->references('id')->on('order_returns')->cascadeOnDelete();
            $table->unsignedBigInteger('order_return_parcel_id')->nullable();
            $table->foreign('order_return_parcel_id', 'orpe_parcel_fk')->references('id')->on('order_return_parcels')->nullOnDelete();
            $table->char('tracking_identity', 64);
            $table->char('event_key', 64)->unique('orpe_event_key_unique');
            $table->uuid('event_id')->unique('orpe_event_id_unique');
            $table->string('provider_status', 100);
            $table->string('tracking_status', 40);
            $table->dateTime('occurred_at', 6);
            $table->string('description', 500)->nullable();
            $table->string('previous_status', 40)->nullable();
            $table->boolean('automation_pending')->default(false)->index('orpe_pending_index');
            $table->index(['order_return_id', 'occurred_at'], 'orpe_return_time_index');
        });
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_return_id')->nullable()->index('auto_run_return_index');
            $table->foreign('order_return_id', 'auto_run_return_fk')->references('id')->on('order_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('order_return_parcel_events')->exists()
            || DB::table('order_return_parcels')->whereNotNull('tracking_checked_at')->orWhere('is_manual', true)->exists()
            || DB::table('automation_runs')->whereNotNull('order_return_id')->exists()) {
            throw new RuntimeException('Nie mozna usunac uzywanej historii sledzenia zwrotow.');
        }
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropForeign('auto_run_return_fk');
            $table->dropIndex('auto_run_return_index');
            $table->dropColumn('order_return_id');
        });
        Schema::drop('order_return_parcel_events');
        Schema::table('order_return_parcels', function (Blueprint $table): void {
            $table->dropIndex('orp_tracking_due_index');
            $table->dropColumn(['is_manual', 'tracking_identity', 'tracking_status', 'provider_status', 'tracking_status_at', 'tracking_started_at', 'tracking_checked_at', 'tracking_next_sync_at', 'tracking_error']);
        });
    }
};
