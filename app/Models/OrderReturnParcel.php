<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Shipments\Models\Shipment;

class OrderReturnParcel extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'submitted_at' => 'immutable_datetime', 'is_manual' => 'boolean', 'order_return_id' => 'integer',
        'tracking_status_at' => UtcDateTime::class, 'tracking_checked_at' => UtcDateTime::class,
        'tracking_next_sync_at' => UtcDateTime::class,
        'tracking_started_at' => UtcDateTime::class,
    ];

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(OrderReturnParcelEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function identity(): string
    {
        return hash('sha256', json_encode([$this->order_return_id, $this->carrier_id, $this->waybill,
            $this->transporting_carrier_id, $this->transporting_waybill], JSON_THROW_ON_ERROR));
    }

    public function hasCurrentTracking(): bool
    {
        return $this->tracking_identity !== null && hash_equals($this->identity(), $this->tracking_identity);
    }

    public function statusCode(): ?string
    {
        return $this->hasCurrentTracking() ? $this->tracking_status : null;
    }

    public function statusLabel(): string
    {
        if ($this->statusCode() === Shipment::OMS_STATUS_RETURNED) {
            return $this->provider_status === 'returning_to_sender' ? 'Wraca do klienta' : 'Zwrócona klientowi';
        }

        return $this->statusCode() ? Shipment::omsStatusLabelFor($this->statusCode()) : 'Brak danych śledzenia';
    }

    public function progress(): int
    {
        return match ($this->statusCode()) {
            Shipment::OMS_STATUS_DISPATCHED => 33, Shipment::OMS_STATUS_OUT_FOR_DELIVERY => 66,
            Shipment::OMS_STATUS_READY_FOR_PICKUP => 90,
            Shipment::OMS_STATUS_DELIVERED, Shipment::OMS_STATUS_RETURNED => 100,
            Shipment::OMS_STATUS_PROBLEM => 50, default => 0,
        };
    }

    public function isTerminal(): bool
    {
        return $this->hasCurrentTracking() && ($this->tracking_status === Shipment::OMS_STATUS_DELIVERED
            || in_array($this->provider_status, ['returned', 'returned_to_sender', 'cancelled', 'canceled'], true));
    }

    public function trackingUrl(): ?string
    {
        $physical = filled($this->transporting_carrier_id) && filled($this->transporting_waybill);
        $carrier = strtoupper((string) ($physical ? $this->transporting_carrier_id : $this->carrier_id));
        $number = $physical ? $this->transporting_waybill : $this->waybill;
        if (blank($number)) {
            return null;
        }

        return match ($carrier) {
            'INPOST' => 'https://inpost.pl/sledzenie-przesylek?number='.rawurlencode($number),
            'DPD' => 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1='.rawurlencode($number),
            default => null,
        };
    }

    public function trackingCarrier(): string
    {
        return strtoupper(trim((string) (filled($this->carrier_id) && filled($this->waybill)
            ? $this->carrier_id : $this->transporting_carrier_id)));
    }

    public function durationLabel(): ?string
    {
        if ($this->statusCode() !== 'delivered' || ! $this->tracking_started_at || ! $this->tracking_status_at) {
            return null;
        }
        $days = (int) $this->tracking_started_at->diffInDays($this->tracking_status_at, true);

        return $days === 1 ? '1 dzień' : $days.' dni';
    }
}
