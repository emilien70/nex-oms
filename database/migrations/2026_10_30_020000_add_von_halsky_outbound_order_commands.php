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
            $table->boolean('status_sync_enabled')->default(false);
            $table->unsignedInteger('status_sync_revision')->default(0);
            $table->json('access_token_scopes')->nullable();
        });
        Schema::create('von_halsky_status_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('von_halsky_account_id');
            $table->foreign('von_halsky_account_id', 'vh_status_map_account_fk')->references('id')->on('von_halsky_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('order_status_setting_id');
            $table->foreign('order_status_setting_id', 'vh_status_map_status_fk')->references('id')->on('order_status_settings')->cascadeOnDelete();
            $table->string('environment', 16);
            $table->uuid('organization_id');
            $table->string('action', 16);
            $table->timestamps();
            $table->unique(['von_halsky_account_id', 'environment', 'organization_id', 'order_status_setting_id'], 'vh_status_mapping_unique');
        });
        Schema::create('von_halsky_order_commands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('von_halsky_account_id')->nullable();
            $table->foreign('von_halsky_account_id', 'vh_command_account_fk')->references('id')->on('von_halsky_accounts')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreign('order_id', 'vh_command_order_fk')->references('id')->on('orders')->nullOnDelete();
            $table->unsignedBigInteger('order_link_id')->nullable()->index('vh_command_link_index');
            $table->foreign('order_link_id', 'vh_command_link_fk')->references('id')->on('von_halsky_order_links')->nullOnDelete();
            $table->unsignedBigInteger('retry_of_id')->nullable();
            $table->foreign('retry_of_id', 'vh_command_retry_fk')->references('id')->on('von_halsky_order_commands')->nullOnDelete();
            $table->string('environment', 16);
            $table->uuid('organization_id');
            $table->string('external_order_id', 255);
            $table->uuid('event_id')->unique('vh_command_event_unique');
            $table->char('operation_key', 64)->nullable()->unique('vh_command_operation_unique');
            $table->string('oms_status', 255);
            $table->string('oms_status_label', 255);
            $table->string('action', 16);
            $table->string('state', 24)->default('queued');
            $table->unsignedInteger('configuration_revision');
            $table->unsignedInteger('connection_revision');
            $table->uuid('command_id')->nullable();
            $table->uuid('sending_token')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('sending_at', 6)->nullable();
            $table->dateTime('submitted_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('next_check_at', 6)->nullable();
            $table->dateTime('last_dispatched_at', 6)->nullable();
            $table->dateTime('retried_at', 6)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();
            $table->index(['state', 'next_check_at'], 'vh_command_due_index');
            $table->unique(['environment', 'organization_id', 'command_id'], 'vh_command_remote_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('von_halsky_order_commands')->exists() || DB::table('von_halsky_status_mappings')->exists()
            || DB::table('von_halsky_accounts')->where('status_sync_enabled', true)->exists()) {
            throw new RuntimeException('Nie mozna usunac uzywanej konfiguracji lub historii polecen Von Halsky.');
        }
        Schema::dropIfExists('von_halsky_order_commands');
        Schema::dropIfExists('von_halsky_status_mappings');
        Schema::table('von_halsky_accounts', fn (Blueprint $table) => $table->dropColumn(['status_sync_enabled', 'status_sync_revision', 'access_token_scopes']));
    }
};
