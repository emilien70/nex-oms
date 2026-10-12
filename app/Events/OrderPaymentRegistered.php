<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ramsey\Uuid\Uuid;

class OrderPaymentRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly Order $order,
        public readonly int $paymentRecordId,
        public readonly string $paymentSource,
        public readonly ?string $providerPaymentId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $paidAmountBefore,
        public readonly string $paidAmountAfter,
        public readonly string $paymentState,
        public readonly string $accountedAt,
    ) {
        $this->eventId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "nex-oms:payment-registered:{$this->paymentSource}:{$this->paymentRecordId}",
        )->toString();
    }

    public function name(): string
    {
        return 'payment.registered';
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'order_id' => $this->order->getKey(),
            'payment_record_id' => $this->paymentRecordId,
            'payment_source' => $this->paymentSource,
            'provider_payment_id' => $this->providerPaymentId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_amount_before' => $this->paidAmountBefore,
            'paid_amount_after' => $this->paidAmountAfter,
            'payment_state' => $this->paymentState,
            'accounted_at' => $this->accountedAt,
            'source' => $this->paymentSource,
        ];
    }
}
