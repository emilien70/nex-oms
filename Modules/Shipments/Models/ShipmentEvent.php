<?php

namespace Modules\Shipments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Shipments\Services\ShipmentStatusMapper;

class ShipmentEvent extends Model
{
    protected $fillable = [
        'shipment_id',
        'event_type',
        'status',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function label(): string
    {
        return match ($this->event_type) {
            'shipment_queued' => 'Dodano przesylke do kolejki',
            'shipment_retry_queued' => 'Ponowiono nadanie przesylki',
            'shipment_created' => 'Utworzono przesylke',
            'shipment_tracking_number_assigned' => 'Przypisano numer nadawczy',
            'shipment_status_changed' => 'Status: '
                .Shipment::omsStatusLabelFor($this->eventOmsStatus('old_oms_status', 'old_status'))
                .' -> '
                .Shipment::omsStatusLabelFor($this->eventOmsStatus('new_oms_status', 'new_status')),
            'shipment_creation_failed' => 'Nie udalo sie utworzyc przesylki',
            default => str_replace('_', ' ', ucfirst($this->event_type)),
        };
    }

    private function eventOmsStatus(string $omsKey, string $providerKey): string
    {
        $omsStatus = data_get($this->payload, $omsKey);

        if (filled($omsStatus)) {
            return (string) $omsStatus;
        }

        return app(ShipmentStatusMapper::class)->map(
            $this->shipment?->provider,
            data_get($this->payload, $providerKey, $this->status),
        );
    }
}
