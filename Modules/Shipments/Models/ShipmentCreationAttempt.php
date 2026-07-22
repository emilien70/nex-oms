<?php

namespace Modules\Shipments\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShipmentCreationAttempt extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'order_id',
        'courier_account_id',
        'provider',
        'request_uuid',
        'status',
        'request_data',
        'error_message',
        'outcome_unknown',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'request_data' => 'array',
        'outcome_unknown' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'creation_attempt_id');
    }

    public function pollingFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_UNKNOWN,
        ], true);
    }
}
