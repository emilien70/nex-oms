<?php

namespace Modules\CustomerPortal\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalLink extends Model
{
    protected $table = 'customer_portal_links';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash', 'identity_hash', 'binding_hash'];

    protected $casts = ['token' => 'encrypted', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'generation' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $link): void {
            if ($link->isDirty(['order_id', 'token', 'token_hash'])
                || ($link->getRawOriginal('binding_hash') !== null && $link->isDirty('binding_hash'))) {
                throw new \LogicException('A customer portal URL cannot be replaced or reassigned.');
            }
            if ($link->isDirty('binding_hash') && ! hash_equals(self::bindingFor((int) $link->order_id, $link->token_hash), (string) $link->binding_hash)) {
                throw new \LogicException('Invalid customer portal binding.');
            }
        });
    }

    public static function bindingFor(int $orderId, string $tokenHash): string
    {
        return hash_hmac('sha256', json_encode(['customer-portal-order-v1', $orderId, $tokenHash], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function isBoundTo(Order $order): bool
    {
        return $this->order_id === $order->id && is_string($this->binding_hash)
            && hash_equals(self::bindingFor($order->id, $this->token_hash), $this->binding_hash);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
