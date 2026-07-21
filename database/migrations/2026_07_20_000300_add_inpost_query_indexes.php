<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->index(['provider', 'created_at', 'id'], 'shipments_provider_created_id_index');
            $table->index(['provider', 'oms_status', 'created_at'], 'shipments_provider_oms_created_index');
            $table->index(['provider', 'status_changed_at'], 'shipments_provider_status_changed_index');
        });

        Schema::table('integration_api_logs', function (Blueprint $table): void {
            $table->index(['shipment_id', 'operation', 'id'], 'api_logs_shipment_operation_id_index');
            $table->index(['successful', 'operation', 'created_at'], 'api_logs_retention_index');
        });
    }

    public function down(): void
    {
        Schema::table('integration_api_logs', function (Blueprint $table): void {
            $table->dropIndex('api_logs_shipment_operation_id_index');
            $table->dropIndex('api_logs_retention_index');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_provider_created_id_index');
            $table->dropIndex('shipments_provider_oms_created_index');
            $table->dropIndex('shipments_provider_status_changed_index');
        });
    }
};
