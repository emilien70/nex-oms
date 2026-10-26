<?php

namespace Modules\Integrations\PayNow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayNowShortLink extends Model
{
    protected $table = 'paynow_short_links';

    protected $fillable = [
        'paynow_payment_id', 'token_hash', 'token', 'identity_hash', 'target_hash', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token', 'token_hash', 'identity_hash', 'target_hash'];

    protected $casts = [
        'token' => 'encrypted',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayNowPayment::class, 'paynow_payment_id');
    }
}
