<?php

namespace Modules\Integrations\VonHalsky\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VonHalskyOrderImportAttempt extends Model
{
    public const ATTENTION = ['needs_quantity', 'blocked', 'failed'];

    protected $guarded = ['id'];

    protected $hidden = ['processing_token'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'external_created_at' => 'immutable_datetime', 'external_updated_at' => 'immutable_datetime',
        'discovered_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime',
        'last_attempt_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
        'processing_expires_at' => 'immutable_datetime', 'attempts' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(VonHalskyAccount::class, 'von_halsky_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
