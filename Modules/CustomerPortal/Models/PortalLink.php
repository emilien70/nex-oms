<?php

namespace Modules\CustomerPortal\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalLink extends Model
{
    protected $table = 'customer_portal_links';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash', 'identity_hash'];

    protected $casts = ['token' => 'encrypted', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'generation' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
