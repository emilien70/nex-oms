<?php

namespace Modules\Shipments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Shipments\Models\Shipment;

class ShipmentDeletionService
{
    public function delete(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment): void {
            $shipmentId = $shipment->getKey();
            $orderId = $shipment->order_id;

            $shipment->apiLogs()->delete();
            $shipment->events()->delete();

            DB::table('order_events')
                ->where('order_id', $orderId)
                ->where(fn ($query) => $query
                    ->where('payload->shipment_id', $shipmentId)
                    ->orWhere('payload->shipment_id', (string) $shipmentId))
                ->delete();

            $this->deleteQueuedJobsForShipment($shipmentId);
            $shipment->delete();
        });
    }

    private function deleteQueuedJobsForShipment(int|string $shipmentId): void
    {
        $needle = 'Modules\\Shipments\\Models\\Shipment";s:2:"id";i:'.$shipmentId.';';

        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $ids = DB::table($table)
                ->where('payload', 'like', '%Shipment%')
                ->get(['id', 'payload'])
                ->filter(function (object $job) use ($needle): bool {
                    $payload = json_decode((string) $job->payload, true);
                    $command = (string) data_get($payload, 'data.command');

                    return str_contains($command, $needle);
                })
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        }
    }
}
