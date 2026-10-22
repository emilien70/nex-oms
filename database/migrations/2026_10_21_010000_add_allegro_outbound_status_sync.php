<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->boolean('status_sync_enabled')->default(false);
            $table->string('status_sync_identity')->nullable();
            $table->unsignedBigInteger('status_sync_revision')->default(0);
        });
        Schema::create('allegro_outbound_status_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_account_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id');
            $table->foreignId('order_status_setting_id')->constrained()->cascadeOnDelete();
            $table->string('allegro_status', 30);
            $table->timestamps();
            $table->unique(['allegro_account_id', 'environment', 'seller_user_id', 'order_status_setting_id'], 'allegro_outbound_mapping_identity_unique');
        });
        Schema::table('allegro_order_links', function (Blueprint $table): void {
            $table->uuid('status_sync_event_id')->nullable();
            $table->string('status_sync_oms_status')->nullable();
            $table->string('status_sync_target', 30)->nullable();
            $table->string('status_sync_state', 20)->nullable();
            $table->text('status_sync_error')->nullable();
            $table->timestamp('status_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('allegro_order_links', function (Blueprint $table): void {
            $table->dropColumn(['status_sync_event_id', 'status_sync_oms_status', 'status_sync_target', 'status_sync_state', 'status_sync_error', 'status_synced_at']);
        });
        Schema::dropIfExists('allegro_outbound_status_mappings');
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->dropColumn(['status_sync_enabled', 'status_sync_identity', 'status_sync_revision']);
        });
    }
};
