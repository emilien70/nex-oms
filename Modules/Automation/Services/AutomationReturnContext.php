<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnParcel;

class AutomationReturnContext
{
    public function parcel(Order $order, array $payload): ?OrderReturnParcel
    {
        if (($payload['event_name'] ?? null) !== 'return.parcel_status_changed' || ($payload['shipment_direction'] ?? null) !== 'return') {
            return null;
        }
        $parcel = OrderReturnParcel::query()->with('orderReturn.localStatus')->find($payload['return_parcel_id'] ?? 0);
        if (! $parcel || ! $parcel->orderReturn || (int) $parcel->orderReturn->order_id !== $order->id
            || $parcel->order_return_id !== (int) ($payload['order_return_id'] ?? 0)
            || ! $parcel->hasCurrentTracking() || $parcel->identity() !== ($payload['tracking_identity'] ?? null)
            || $parcel->statusCode() !== ($payload['new_status'] ?? null)) {
            return null;
        }
        $event = $parcel->trackingEvents()->whereKey($payload['return_parcel_event_id'] ?? 0)
            ->where('event_id', $payload['event_id'] ?? '')->where('tracking_identity', $parcel->identity())
            ->where('tracking_status', $parcel->statusCode())->first();

        return $event && $parcel->tracking_status_at?->equalTo($event->occurred_at) ? $parcel : null;
    }

    public function allDelivered(OrderReturn $record): bool
    {
        $parcels = $record->parcels()->get();

        return $parcels->isNotEmpty() && $parcels->every(fn ($parcel) => $parcel->statusCode() === 'delivered');
    }
}
