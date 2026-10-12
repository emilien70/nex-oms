<?php

namespace Modules\Integrations\PayNow\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;

class PayNowPayment extends Model
{
    protected $table = 'paynow_payments';

    protected $fillable = [
        'paynow_account_id',
        'order_id',
        'amount',
        'currency',
        'external_id',
        'provider_payment_id',
        'idempotency_key',
        'payment_url',
        'status',
        'buyer_email_snapshot',
        'description',
        'valid_until',
        'provider_modified_at',
        'last_status_checked_at',
        'confirmed_at',
        'accounted_at',
        'needs_review',
        'safe_error_code',
        'safe_error_message',
    ];

    protected $hidden = [
        'payment_url',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_url' => 'encrypted',
        'status' => PayNowPaymentStatus::class,
        'valid_until' => 'immutable_datetime',
        'provider_modified_at' => 'immutable_datetime',
        'last_status_checked_at' => 'immutable_datetime',
        'confirmed_at' => 'immutable_datetime',
        'accounted_at' => 'immutable_datetime',
        'needs_review' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayNowAccount::class, 'paynow_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PayNowPaymentEvent::class, 'paynow_payment_id');
    }
}
