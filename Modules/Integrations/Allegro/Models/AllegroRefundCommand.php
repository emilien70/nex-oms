<?php

namespace Modules\Integrations\Allegro\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AllegroRefundCommand extends Model
{
    public const RESERVING_STATES = ['queued', 'sending', 'submitted', 'uncertain', 'review'];

    public const REASONS = [
        'REFUND' => 'Zwrot towaru', 'COMPLAINT' => 'Reklamacja',
        'PRODUCT_NOT_AVAILABLE' => 'Produkt niedostępny', 'CANCELLED_BY_BUYER' => 'Anulowane przez kupującego',
        'NOT_COLLECTED' => 'Przesyłka nieodebrana',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['session_hash', 'payload', 'payload_hash', 'context_hash'];

    protected $casts = [
        'allegro_account_id' => 'integer', 'order_id' => 'integer', 'order_return_id' => 'integer',
        'allegro_payment_refund_id' => 'integer', 'return_number' => 'integer',
        'payload' => 'array', 'selection' => 'array', 'products_value' => 'decimal:2',
        'delivery_value' => 'decimal:2', 'total_value' => 'decimal:2', 'expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime',
        'configuration_revision' => 'integer', 'return_version' => 'integer', 'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $command): void {
            $mutable = ['state', 'attempts', 'started_at', 'next_check_at', 'error', 'allegro_payment_refund_id', 'external_refund_id', 'updated_at'];
            if (array_diff(array_keys($command->getDirty()), $mutable) !== []) {
                throw new LogicException('Zatwierdzone dane zlecenia refundacji są niezmienne.');
            }
            if ($command->isDirty('external_refund_id') && $command->getOriginal('external_refund_id') !== null) {
                throw new LogicException('Nie można zmienić powiązanej refundacji Allegro.');
            }
        });
        static::deleting(fn () => throw new LogicException('Nie można usuwać historii zleceń refundacji.'));
    }

    public function identity(): array
    {
        return $this->only(['environment', 'seller_user_id', 'external_order_id']);
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            'draft' => 'Oczekuje na potwierdzenie', 'queued' => 'Oczekuje na zlecenie', 'sending' => 'Zlecanie',
            'submitted' => 'Przyjęte przez Allegro — w toku', 'success' => 'Zwrot potwierdzony',
            'failed' => 'Odrzucone', 'cancelled' => 'Nie wysłano', 'uncertain' => 'Wynik nieznany — wymaga sprawdzenia',
            default => 'Wymaga sprawdzenia',
        };
    }
}
