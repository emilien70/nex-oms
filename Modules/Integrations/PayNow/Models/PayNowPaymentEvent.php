<?php

namespace Modules\Integrations\PayNow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;

class PayNowPaymentEvent extends Model
{
    protected $table = 'paynow_payment_events';

    protected $fillable = [
        'paynow_payment_id',
        'event_key',
        'source',
        'provider_payment_id',
        'external_id',
        'status',
        'provider_modified_at',
        'received_at',
        'payload',
        'payload_sha256',
    ];

    protected $hidden = [
        'payload',
    ];

    protected $casts = [
        'status' => PayNowPaymentStatus::class,
        'provider_modified_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'payload' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('PayNow payment events cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('PayNow payment events cannot be deleted.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayNowPayment::class, 'paynow_payment_id');
    }
}
