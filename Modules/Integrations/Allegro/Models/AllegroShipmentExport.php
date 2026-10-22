<?php

namespace Modules\Integrations\Allegro\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Shipments\Models\Shipment;

class AllegroShipmentExport extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'aliases' => 'array', 'verification_only' => 'boolean', 'configuration_revision' => 'integer',
        'confirmed_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AllegroOrderLink::class, 'allegro_order_link_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'waiting' => 'Czeka na status OMS', 'pending' => 'Oczekuje',
            'synced' => $this->verification_only ? 'Potwierdzono paczkę w Allegro' : 'Potwierdzono w Allegro',
            'failed' => 'Błąd przekazania', default => 'Pominięto',
        };
    }
}
