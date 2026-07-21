<?php

namespace Modules\Shipments\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationApiLog extends Model
{
    protected $fillable = [
        'integration',
        'operation',
        'order_id',
        'shipment_id',
        'request_id',
        'method',
        'url',
        'request_payload',
        'response_status',
        'response_payload',
        'duration_ms',
        'successful',
        'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'successful' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
