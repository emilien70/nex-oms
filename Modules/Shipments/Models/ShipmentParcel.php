<?php

namespace Modules\Shipments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentParcel extends Model
{
    protected $fillable = [
        'shipment_id',
        'position',
        'package_type',
        'external_id',
        'tracking_number',
        'weight',
        'length',
        'width',
        'height',
        'is_non_standard',
    ];

    protected $casts = [
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'is_non_standard' => 'boolean',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
