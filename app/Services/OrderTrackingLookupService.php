<?php

namespace App\Services;

use App\Support\LiteralLike;
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
        if ($operator === 'like') {
            LiteralLike::where($query, 'tracking_number', $trackingNumber);
            $query->orWhereHas('parcels', function (Builder $parcelQuery) use ($trackingNumber): void {
                LiteralLike::where($parcelQuery, 'tracking_number', $trackingNumber);
            });

            return;
        }

        $query
            ->where('tracking_number', $operator, $trackingNumber)
            ->orWhereHas('parcels', function (Builder $parcelQuery) use ($trackingNumber, $operator): void {
                $parcelQuery->where('tracking_number', $operator, $trackingNumber);
            });
    }
}
