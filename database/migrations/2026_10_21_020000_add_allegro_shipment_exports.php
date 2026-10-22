<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->string('shipment_sync_mode', 20)->default('disabled');
            $table->foreignId('shipment_sync_status_setting_id')->nullable()->constrained('order_status_settings')->nullOnDelete();
            $table->string('shipment_sync_identity')->nullable();
            $table->unsignedBigInteger('shipment_sync_revision')->default(0);
        });
        Schema::table('shipment_parcels', function (Blueprint $table): void {
            $table->json('tracking_numbers')->nullable();
        });
        Schema::create('allegro_shipment_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allegro_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('allegro_order_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('seller_user_id');
            $table->uuid('external_order_id');
            $table->string('carrier_id', 50);
            $table->string('waybill');
            $table->json('aliases')->nullable();
            $table->string('fingerprint', 64)->unique();
            $table->boolean('verification_only')->default(false);
            $table->unsignedBigInteger('configuration_revision');
            $table->uuid('attempt_token');
            $table->string('state', 20);
            $table->text('error')->nullable();
            $table->string('remote_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allegro_shipment_exports');
        Schema::table('shipment_parcels', fn (Blueprint $table) => $table->dropColumn('tracking_numbers'));
        Schema::table('allegro_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipment_sync_status_setting_id');
            $table->dropColumn(['shipment_sync_mode', 'shipment_sync_identity', 'shipment_sync_revision']);
        });
    }
};
