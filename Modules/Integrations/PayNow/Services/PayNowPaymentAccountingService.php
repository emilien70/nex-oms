<?php

namespace Modules\Integrations\PayNow\Services;

use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use App\Services\OrderPaymentStateService;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PayNowPaymentAccountingService
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly OrderPaymentStateService $paymentState,
    ) {}

    public function account(PayNowPayment $payment): PayNowPayment
    {
        return DB::transaction(function () use ($payment): PayNowPayment {
            $managed = PayNowPayment::query()
                ->lockForUpdate()
                ->findOrFail($payment->getKey());

            if ($managed->status !== PayNowPaymentStatus::Confirmed || $managed->accounted_at !== null) {
                return $managed;
            }

            $order = $managed->order_id === null
                ? null
                : Order::withTrashed()->lockForUpdate()->find($managed->order_id);

            if (! $order || $order->trashed()) {
                return $this->markForReview(
                    $managed,
                    'order_unavailable',
                    'Nie można zaksięgować płatności, ponieważ zamówienie nie istnieje lub znajduje się w koszu.',
                );
            }

            if (strtoupper((string) $order->currency) !== strtoupper((string) $managed->currency)) {
                return $this->markForReview(
                    $managed,
                    'currency_mismatch',
                    'Waluta płatności PayNow nie jest zgodna z walutą zamówienia.',
                );
            }

            try {
                $before = $this->decimal->normalize((string) $order->paid_amount, 2);
                $after = $this->decimal->add($before, (string) $managed->amount);
                $total = $this->decimal->normalize((string) $order->total_gross, 2);

                if ($this->decimal->compare($after, $total) > 0) {
                    return $this->markForReview(
                        $managed,
                        'overpayment_detected',
                        'Kwota płatności przekracza pozostałą wartość zamówienia i wymaga sprawdzenia.',
                    );
                }

                $state = $this->paymentState->forPaidAmountUpdate($order, $after);
            } catch (InvoiceDomainException) {
                return $this->markForReview(
                    $managed,
                    'payment_state_invalid',
                    'Nie można bezpiecznie zaktualizować stanu płatności zamówienia.',
                );
            }

            $accountedAt = now();
            $order->forceFill([
                'paid_amount' => $state['paid_amount'],
                'payment_status' => $state['payment_status'],
                'paid_at' => $state['payment_status'] === OrderPaymentStateService::STATUS_PAID
                    ? ($order->paid_at ?? $managed->confirmed_at ?? $accountedAt)
                    : null,
            ])->save();

            $managed->forceFill([
                'accounted_at' => $accountedAt,
                'needs_review' => false,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            $payload = [
                'order_id' => $order->getKey(),
                'amount' => $managed->amount,
                'currency' => $managed->currency,
                'paid_amount_before' => $before,
                'paid_amount_after' => $state['paid_amount'],
                'payment_status_after' => $state['payment_status'],
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $managed->events()->firstOrCreate([
                'event_key' => hash('sha256', 'accounting|'.$managed->getKey()),
            ], [
                'source' => 'accounting',
                'provider_payment_id' => (string) $managed->provider_payment_id,
                'external_id' => $managed->external_id,
                'status' => PayNowPaymentStatus::Confirmed,
                'provider_modified_at' => $managed->provider_modified_at,
                'received_at' => $accountedAt,
                'payload' => $payload,
                'payload_sha256' => hash('sha256', $payloadJson),
            ]);

            $order->events()->create([
                'event_type' => 'paynow_payment_registered',
                'title' => 'Zarejestrowano płatność PayNow',
                'description' => 'Kwota: '.$managed->amount.' '.$managed->currency,
                'payload' => [
                    'paynow_payment_id' => $managed->getKey(),
                    'provider_payment_id' => $managed->provider_payment_id,
                    ...$payload,
                ],
            ]);

            $automationPaymentState = $state['payment_status'] === OrderPaymentStateService::STATUS_PAID
                ? 'paid'
                : ($this->decimal->compare($state['paid_amount'], '0.00') > 0 ? 'partial' : 'unpaid');

            OrderPaymentRegistered::dispatch(
                order: $order,
                paymentRecordId: (int) $managed->getKey(),
                paymentSource: 'paynow',
                providerPaymentId: $managed->provider_payment_id,
                amount: (string) $managed->amount,
                currency: (string) $managed->currency,
                paidAmountBefore: $before,
                paidAmountAfter: $state['paid_amount'],
                paymentState: $automationPaymentState,
                accountedAt: $accountedAt->toIso8601String(),
            );

            return $managed->refresh();
        }, 3);
    }

    private function markForReview(
        PayNowPayment $payment,
        string $code,
        string $message,
    ): PayNowPayment {
        $payment->forceFill([
            'needs_review' => true,
            'safe_error_code' => $code,
            'safe_error_message' => $message,
        ])->save();

        return $payment;
    }
}
