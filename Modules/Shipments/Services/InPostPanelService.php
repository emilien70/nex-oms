<?php

namespace Modules\Shipments\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class InPostPanelService
{
    public const PER_PAGE_OPTIONS = [20, 30, 50, 75, 100, 150, 200];

    public function shipments(
        array $filters,
        int $perPage,
        string $provider = CourierAccount::PROVIDER_INPOST_LOCKERS,
    ): LengthAwarePaginator {
        $query = Shipment::query()
            ->where('provider', $provider)
            ->with([
                'order:id,customer_phone,customer_email',
                'courierAccount:id,name,provider',
                'parcels',
                'latestCreateApiLog',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (filled($filters['tracking_number'] ?? null)) {
            $query->where('tracking_number', 'like', $this->prefixLike($filters['tracking_number']));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('oms_status', $filters['status']);
        }

        if (filled($filters['service'] ?? null)) {
            $query->where('service', $filters['service']);
        }

        if (filled($filters['order_id'] ?? null)) {
            $orderId = trim((string) $filters['order_id']);
            $query->where('order_id', ctype_digit($orderId) ? (int) $orderId : 0);
        }

        if (($filters['cod'] ?? null) === 'yes') {
            $query->where('cod_amount', '>', 0);
        } elseif (($filters['cod'] ?? null) === 'no') {
            $query->where(fn ($nested) => $nested->whereNull('cod_amount')->orWhere('cod_amount', '<=', 0));
        }

        if (($filters['has_errors'] ?? null) === 'yes') {
            $query->where(fn ($nested) => $nested->where('status', Shipment::STATUS_ERROR)->orWhereNotNull('error_message'));
        } elseif (($filters['has_errors'] ?? null) === 'no') {
            $query->where('status', '!=', Shipment::STATUS_ERROR)->whereNull('error_message');
        }

        if (filled($filters['sending_method'] ?? null)) {
            $query->where('sending_method', $filters['sending_method']);
        }

        if (filled($filters['created_from'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay());
        }

        if (filled($filters['created_to'] ?? null)) {
            $query->where('created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay());
        }

        if (filled($filters['status_from'] ?? null)) {
            $query->where('status_changed_at', '>=', Carbon::parse($filters['status_from'])->startOfDay());
        }

        if (filled($filters['status_to'] ?? null)) {
            $query->where('status_changed_at', '<=', Carbon::parse($filters['status_to'])->endOfDay());
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function statusOptions(): array
    {
        return Shipment::omsStatuses();
    }

    private function prefixLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], trim($value)).'%';
    }
}
