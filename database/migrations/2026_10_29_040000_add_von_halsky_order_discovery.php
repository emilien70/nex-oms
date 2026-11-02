<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('von_halsky_accounts', function (Blueprint $table): void {
            $table->boolean('order_import_enabled')->default(false);
            $table->unsignedSmallInteger('order_import_interval_minutes')->default(10);
            $table->unsignedInteger('order_import_revision')->default(0);
            $table->dateTime('order_import_started_at')->nullable();
            $table->dateTime('order_import_cursor_at')->nullable();
            $table->dateTime('last_order_scan_at')->nullable();
            $table->dateTime('last_order_scan_requested_at')->nullable();
            $table->dateTime('last_order_reconciliation_at')->nullable();
            $table->string('last_order_import_error', 1000)->nullable();
        });
        Schema::create('von_halsky_order_import_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('von_halsky_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->uuid('organization_id');
            $table->string('external_order_id');
            $table->string('external_status', 50)->nullable();
            $table->dateTime('external_created_at', 6);
            $table->dateTime('external_updated_at', 6);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('discovered_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('last_attempt_at', 6)->nullable();
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->string('last_message', 1000)->nullable();
            $table->uuid('processing_token')->nullable();
            $table->dateTime('processing_expires_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'external_order_id'], 'vh_import_identity_unique');
            $table->index(['von_halsky_account_id', 'status', 'next_attempt_at'], 'vh_import_pending_index');
        });
    }

    public function down(): void
    {
        if (DB::table('von_halsky_order_import_attempts')->exists()
            || DB::table('von_halsky_accounts')->where('order_import_enabled', true)->orWhereNotNull('order_import_started_at')->exists()) {
            throw new RuntimeException('Nie mozna usunac uzywanej historii lub ustawien pobierania Von Halsky.');
        }
        Schema::drop('von_halsky_order_import_attempts');
        Schema::table('von_halsky_accounts', function (Blueprint $table): void {
            $table->dropColumn(['order_import_enabled', 'order_import_interval_minutes', 'order_import_revision',
                'order_import_started_at', 'order_import_cursor_at', 'last_order_scan_at',
                'last_order_scan_requested_at', 'last_order_reconciliation_at', 'last_order_import_error']);
        });
    }
};
