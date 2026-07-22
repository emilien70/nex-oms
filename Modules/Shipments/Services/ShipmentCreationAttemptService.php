<?php

namespace Modules\Shipments\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;

class ShipmentCreationAttemptService
{
    public function begin(Order $order, CourierAccount $account, array $requestData): ShipmentCreationAttempt
    {
        return $order->shipmentCreationAttempts()->create([
            'courier_account_id' => $account->id,
            'provider' => $account->provider,
            'request_uuid' => (string) Str::uuid(),
            'status' => ShipmentCreationAttempt::STATUS_QUEUED,
            'request_data' => $requestData,
        ]);
    }

    public function attach(ShipmentCreationAttempt $attempt, Shipment $shipment): ShipmentCreationAttempt
    {
        $shipment->update([
            'creation_attempt_id' => $attempt->id,
            'request_uuid' => $attempt->request_uuid,
        ]);

        $attempt->update([
            'status' => ShipmentCreationAttempt::STATUS_PROCESSING,
            'started_at' => $attempt->started_at ?: now(),
        ]);

        return $attempt->fresh()->setRelation('shipment', $shipment->fresh());
    }

    public function succeed(Shipment $shipment): ?ShipmentCreationAttempt
    {
        if (blank($shipment->tracking_number)) {
            return null;
        }

        $attempt = $shipment->creationAttempt;

        if (! $attempt || $attempt->status === ShipmentCreationAttempt::STATUS_SUCCEEDED) {
            return $attempt;
        }

        $attempt->update([
            'status' => ShipmentCreationAttempt::STATUS_SUCCEEDED,
            'error_message' => null,
            'outcome_unknown' => false,
            'completed_at' => now(),
        ]);

        return $attempt->fresh();
    }

    public function fail(Shipment $shipment, string $message, bool $outcomeUnknown): ShipmentCreationAttempt
    {
        return DB::transaction(function () use ($shipment, $message, $outcomeUnknown): ShipmentCreationAttempt {
            $shipment->loadMissing(['order', 'creationAttempt']);
            $attempt = $shipment->creationAttempt ?: $this->attemptFromExistingShipment($shipment);

            if (in_array($attempt->status, [
                ShipmentCreationAttempt::STATUS_SUCCEEDED,
                ShipmentCreationAttempt::STATUS_FAILED,
                ShipmentCreationAttempt::STATUS_UNKNOWN,
            ], true)) {
                return $attempt;
            }

            $attemptStatus = $outcomeUnknown
                ? ShipmentCreationAttempt::STATUS_UNKNOWN
                : ShipmentCreationAttempt::STATUS_FAILED;
            $shipmentStatus = $outcomeUnknown
                ? Shipment::STATUS_CREATION_UNKNOWN
                : Shipment::STATUS_CREATION_FAILED;

            $attempt->update([
                'status' => $attemptStatus,
                'error_message' => $message,
                'outcome_unknown' => $outcomeUnknown,
                'completed_at' => now(),
            ]);

            $shipment->update([
                'status' => $shipmentStatus,
                'status_changed_at' => now(),
                'error_message' => $message,
            ]);

            $shipment->order?->events()->create([
                'event_type' => 'shipment_creation_failed',
                'title' => 'Nie udalo sie utworzyc przesylki',
                'description' => $message,
                'payload' => [
                    'shipment_attempt_id' => $attempt->id,
                    'provider' => $attempt->provider,
                    'outcome_unknown' => $outcomeUnknown,
                ],
            ]);

            ShipmentCreationFailed::dispatch($attempt->fresh(), $outcomeUnknown);

            if (! $outcomeUnknown) {
                $shipment->delete();
            }

            return $attempt->fresh();
        });
    }

    private function attemptFromExistingShipment(Shipment $shipment): ShipmentCreationAttempt
    {
        $requestUuid = $shipment->request_uuid ?: (string) Str::uuid();
        $attempt = ShipmentCreationAttempt::query()->firstOrCreate(
            ['request_uuid' => $requestUuid],
            [
                'order_id' => $shipment->order_id,
                'courier_account_id' => $shipment->courier_account_id,
                'provider' => $shipment->provider,
                'status' => ShipmentCreationAttempt::STATUS_PROCESSING,
                'started_at' => $shipment->created_at ?: now(),
            ],
        );

        $shipment->update(['creation_attempt_id' => $attempt->id]);

        return $attempt;
    }
}
