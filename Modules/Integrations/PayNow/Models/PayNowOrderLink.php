<?php

namespace Modules\Integrations\PayNow\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayNowOrderLink extends Model
{
    protected $table = 'paynow_order_links';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash', 'buyer_hash'];

    protected $casts = ['token' => 'encrypted'];

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            if ($link->isDirty('order_id')) {
                throw new \LogicException('Nie można przypisać wydanego linku płatności do innego zamówienia.');
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(PayNowShortLink::class, 'paynow_short_link_id');
    }
}
