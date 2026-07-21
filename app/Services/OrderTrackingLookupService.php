<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrderTrackingLookupService
{
    public function matchingOrderIds(Builder $ordersQuery, string $trackingNumber, int $limit = 2): Collection
    {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            return collect();
        }

        return $ordersQuery
            ->whereHas('shipments', function (Builder $shipmentQuery) use ($trackingNumber): void {
                $this->constrainShipmentQuery($shipmentQuery, $trackingNumber, '=');
            })
            ->limit($limit)
            ->pluck('orders.id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function constrainShipmentQuery(
        Builder $query,
        string $trackingNumber,
        string $operator = 'like'
    ): void {
        $query
            ->where('tracking_number', $operator, $trackingNumber)
            ->orWhereHas('parcels', function (Builder $parcelQuery) use ($trackingNumber, $operator): void {
                $parcelQuery->where('tracking_number', $operator, $trackingNumber);
            });
    }
}
