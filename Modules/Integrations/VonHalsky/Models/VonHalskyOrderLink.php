<?php

namespace Modules\Integrations\VonHalsky\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderStatus;

class VonHalskyOrderLink extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payment_evidence'];

    protected $casts = [
        'invoice_required' => 'boolean',
        'payment_evidence' => 'encrypted:array',
        'channel_paid_amount' => 'decimal:2',
        'channel_paid_at' => 'immutable_datetime',
        'quantities_snapshot' => 'array',
        'automations_suppressed' => 'boolean',
        'imported_at' => 'immutable_datetime',
        'external_status_updated_at' => 'immutable_datetime',
        'last_status_checked_at' => 'immutable_datetime',
        'last_status_sync_requested_at' => 'immutable_datetime',
    ];

    public function externalOrderStatus(): VonHalskyOrderStatus
    {
        return VonHalskyOrderStatus::tryFrom((string) $this->external_status) ?? VonHalskyOrderStatus::Unknown;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VonHalskyAccount::class, 'von_halsky_account_id');
    }

    public function latestCommand(): HasOne
    {
        return $this->hasOne(VonHalskyOrderCommand::class, 'order_link_id')->latestOfMany();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
