<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('von_halsky_order_links', function (Blueprint $table): void {
            $table->dateTime('external_status_updated_at', 6)->nullable();
            $table->dateTime('last_status_checked_at', 6)->nullable();
            $table->dateTime('last_status_sync_requested_at', 6)->nullable();
            $table->string('last_status_sync_error', 1000)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('von_halsky_order_links')->whereNotNull('external_status_updated_at')
            ->orWhereNotNull('last_status_checked_at')->orWhereNotNull('last_status_sync_requested_at')
            ->orWhereNotNull('last_status_sync_error')->exists()) {
            throw new RuntimeException('Nie mozna usunac uzywanych danych synchronizacji statusow Von Halsky.');
        }
        Schema::table('von_halsky_order_links', function (Blueprint $table): void {
            $table->dropColumn(['external_status_updated_at', 'last_status_checked_at', 'last_status_sync_requested_at', 'last_status_sync_error']);
        });
    }
};
