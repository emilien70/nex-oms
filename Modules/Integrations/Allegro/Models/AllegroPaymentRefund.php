<?php

namespace Modules\Integrations\Allegro\Models;

use App\Models\Order;
use App\Models\OrderReturnRefundAllocation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AllegroPaymentRefund extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'total_value' => 'decimal:2', 'products_value' => 'decimal:2', 'delivery_value' => 'decimal:2', 'other_value' => 'decimal:2',
        'components' => 'array', 'requested_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime',
        'next_sync_at' => 'immutable_datetime', 'lock_version' => 'integer',
        'allegro_account_id' => 'integer', 'order_id' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderReturnRefundAllocation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AllegroPaymentRefundEvent::class)->orderByDesc('id');
    }

    public function command(): HasOne
    {
        return $this->hasOne(AllegroRefundCommand::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'SUCCESS' => 'Zwrócono', 'NEW' => 'Przyjęto zlecenie', 'WAITING', 'VALIDATED' => 'Oczekuje',
            'IN_PROGRESS' => 'W realizacji', 'PARTIAL' => 'Częściowo wykonane — wymaga sprawdzenia',
            'CANCELED', 'CANCELLED' => 'Niewykonane', 'VALIDATE_FAILED' => 'Błąd weryfikacji',
            default => 'Nieznany status: '.$this->status,
        };
    }

    public function allocationsAreValid(): bool
    {
        if ($this->status !== 'SUCCESS' || $this->products_value === null) {
            return false;
        }
        $products = BigDecimal::zero();
        $delivery = BigDecimal::zero();
        foreach ($this->allocations as $allocation) {
            if ($allocation->currency !== $this->currency) {
                return false;
            }
            $products = $products->plus($allocation->products_value);
            $delivery = $delivery->plus($allocation->delivery_value);
        }

        return $products->isLessThanOrEqualTo($this->products_value) && $delivery->isLessThanOrEqualTo($this->delivery_value);
    }
}
