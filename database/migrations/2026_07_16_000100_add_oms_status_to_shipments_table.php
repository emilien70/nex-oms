<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('oms_status')->default('created')->index()->after('status');
            $table->timestamp('oms_status_changed_at')->nullable()->index()->after('status_changed_at');
        });

        $mappings = [
            'created' => ['queued', 'created', 'confirmed', 'offers_prepared', 'offer_selected'],
            'dispatched' => [
                'ready_to_pickup_from_pok', 'dispatched_by_sender_to_pok', 'dispatched_by_sender',
                'collected_from_sender', 'taken_by_courier', 'adopted_at_source_branch',
                'sent_from_source_branch', 'sent_from_sorting_center', 'readdressed',
                'taken_by_courier_from_pok', 'unstack_from_customer_service_point',
                'taken_by_courier_from_customer_service_point', 'unstack_from_box_machine',
                'adopted_at_sorting_center', 'redirect_to_box', 'in_transit',
            ],
            'out_for_delivery' => ['out_for_delivery', 'out_for_delivery_to_address'],
            'ready_for_pickup' => [
                'ready_to_pickup', 'ready_to_pickup_from_branch', 'pickup_reminder_sent',
                'pickup_reminder_sent_address', 'avizo', 'courier_avizo_in_customer_service_point',
                'stack_in_customer_service_point', 'stack_in_box_machine',
            ],
            'delivered' => ['delivered'],
            'returned' => ['returned_to_sender', 'returning_to_sender'],
        ];

        DB::table('shipments')->update(['oms_status' => 'problem']);

        foreach ($mappings as $omsStatus => $providerStatuses) {
            DB::table('shipments')
                ->whereIn('status', $providerStatuses)
                ->update(['oms_status' => $omsStatus]);
        }

        DB::table('shipments')->update([
            'oms_status_changed_at' => DB::raw('COALESCE(status_changed_at, updated_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['oms_status']);
            $table->dropIndex(['oms_status_changed_at']);
            $table->dropColumn(['oms_status', 'oms_status_changed_at']);
        });
    }
};
