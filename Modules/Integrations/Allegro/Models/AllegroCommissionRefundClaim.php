<?php

namespace Modules\Integrations\Allegro\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AllegroCommissionRefundClaim extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['context_hash', 'account_identity'];

    protected $casts = [
        'allegro_account_id' => 'integer', 'order_id' => 'integer', 'order_return_id' => 'integer',
        'return_number' => 'integer', 'quantity' => 'integer', 'purchased_quantity' => 'integer',
        'claimed_quantity' => 'integer', 'return_version' => 'integer', 'expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime',
        'observed_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $claim): void {
            $mutable = ['state', 'started_at', 'external_claim_id', 'claimed_quantity', 'status', 'claim_type',
                'submitted_at', 'observed_at', 'next_check_at', 'error', 'updated_at'];
            if (($claim->getOriginal('started_at') !== null || $claim->getOriginal('external_claim_id') !== null)
                && array_diff(array_keys($claim->getDirty()), $mutable) !== []) {
                throw new LogicException('Treść wysłanego wniosku o prowizję jest niezmienna.');
            }
            if ($claim->isDirty('external_claim_id') && $claim->getOriginal('external_claim_id') !== null) {
                throw new LogicException('Nie można zmienić tożsamości wniosku Allegro.');
            }
        });
        static::deleting(fn () => throw new LogicException('Nie można usuwać historii wniosków o prowizję.'));
    }

    public function identity(): array
    {
        return $this->only(['environment', 'seller_user_id', 'external_order_id']);
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'IN_PROGRESS' => 'Wniosek w trakcie rozpatrywania',
            'WAITING_FOR_PAYMENT_REFUND' => 'Oczekuje na zwrot wpłaty kupującemu',
            'GRANTED' => 'Przyznano zwrot prowizji', 'REJECTED' => 'Odmowa zwrotu prowizji',
            'REJECTED_AFTER_APPEAL' => 'Odmowa po odwołaniu', 'CANCELLED' => 'Wniosek anulowany',
            'APPEALED' => 'Odwołanie w trakcie rozpatrywania', default => 'Nieznany status: '.$status,
        };
    }

    public function stateLabel(): string
    {
        return $this->status ? self::statusLabel($this->status) : match ($this->state) {
            'queued' => 'Oczekuje na wysłanie', 'sending' => 'Wysyłanie wniosku',
            'submitted' => 'Wniosek złożony w Allegro', 'not_sent' => 'Nie wysłano wniosku',
            'failed' => 'Allegro odrzuciło żądanie', default => 'Wynik wymaga sprawdzenia',
        };
    }
}
