<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Events\OrderPaymentRegistered;
use App\Services\OrderPaymentStateService;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PrestashopPaymentAccountingService
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly OrderPaymentStateService $paymentStates,
    ) {}

    public function account(int $paymentId, string $remotePaidAmount): string
    {
        return DB::transaction(function () use ($paymentId, $remotePaidAmount): string {
            $payment = PrestashopOrderPayment::query()
                ->with('orderLink.order')
                ->lockForUpdate()
                ->findOrFail($paymentId);

            if ($payment->accounting_status !== PrestashopOrderPayment::STATUS_PENDING) {
                return $payment->accounting_status;
            }

            $order = $payment->orderLink?->order;

            if (! $order || $order->trashed()) {
                return $this->markForReview(
                    $payment,
                    'order_unavailable',
                    'Powiązane zamówienie OMS nie jest dostępne.',
                );
            }

            if (strtoupper((string) $payment->currency) !== strtoupper((string) $order->currency)) {
                return $this->markForReview(
                    $payment,
                    'currency_mismatch',
                    'Waluta płatności PrestaShop nie jest zgodna z walutą zamówienia.',
                );
            }

            $matchingPayNow = $this->matchingPayNowPayment($payment, $order->getKey());

            if ($matchingPayNow) {
                if (
                    $this->decimal->compare((string) $matchingPayNow->amount, (string) $payment->amount) !== 0
                    || strtoupper((string) $matchingPayNow->currency) !== strtoupper((string) $payment->currency)
                ) {
                    return $this->markForReview(
                        $payment,
                        'paynow_transaction_mismatch',
                        'Identyfikator transakcji istnieje w PayNow, ale kwota lub waluta jest inna.',
                    );
                }

                $payment->forceFill([
                    'accounting_status' => PrestashopOrderPayment::STATUS_MIRRORED,
                    'needs_review' => false,
                    'safe_error_code' => null,
                    'safe_error_message' => null,
                    'matched_payment_source' => 'paynow',
                    'matched_payment_record_id' => $matchingPayNow->getKey(),
                ])->save();

                return PrestashopOrderPayment::STATUS_MIRRORED;
            }

            try {
                $before = $this->decimal->normalize((string) $order->paid_amount, 2);
                $remotePaid = $this->decimal->normalize($remotePaidAmount, 2);

                if ($this->decimal->compare($remotePaid, $before) <= 0) {
                    return $this->markForReview(
                        $payment,
                        'possible_duplicate_payment',
                        'Kwota mogła zostać wcześniej zaksięgowana w OMS z innego źródła.',
                    );
                }

                $after = $this->decimal->add($before, (string) $payment->amount);
                $total = $this->decimal->normalize((string) $order->total_gross, 2);

                if ($this->decimal->compare($after, $total) > 0) {
                    return $this->markForReview(
                        $payment,
                        'overpayment_detected',
                        'Płatność przekracza pozostałą wartość zamówienia.',
                    );
                }

                $state = $this->paymentStates->forPaidAmountUpdate($order, $after);
            } catch (InvoiceDomainException) {
                return $this->markForReview(
                    $payment,
                    'payment_state_invalid',
                    'Nie można bezpiecznie zaktualizować stanu płatności zamówienia.',
                );
            }

            $accountedAt = now();
            $order->forceFill([
                'paid_amount' => $state['paid_amount'],
                'payment_status' => $state['payment_status'],
                'payment_method' => $order->payment_method ?: $payment->method,
                'paid_at' => $state['payment_status'] === OrderPaymentStateService::STATUS_PAID
                    ? ($order->paid_at ?? $payment->paid_at ?? $accountedAt)
                    : null,
            ])->save();

            $payment->forceFill([
                'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
                'accounted_at' => $accountedAt,
                'needs_review' => false,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            $paymentState = $state['payment_status'] === OrderPaymentStateService::STATUS_PAID
                ? 'paid'
                : ($this->decimal->compare($state['paid_amount'], '0.00') > 0 ? 'partial' : 'unpaid');
            $providerPaymentId = $payment->transaction_id ?: (string) $payment->external_payment_id;

            $order->events()->create([
                'event_type' => 'prestashop_payment_registered',
                'title' => 'Zarejestrowano płatność PrestaShop',
                'description' => 'Kwota: '.$payment->amount.' '.$payment->currency,
                'payload' => [
                    'prestashop_order_payment_id' => $payment->getKey(),
                    'external_payment_id' => $payment->external_payment_id,
                    'transaction_id' => $payment->transaction_id,
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'paid_amount_before' => $before,
                    'paid_amount_after' => $state['paid_amount'],
                    'payment_status_after' => $state['payment_status'],
                ],
            ]);

            OrderPaymentRegistered::dispatch(
                order: $order,
                paymentRecordId: (int) $payment->getKey(),
                paymentSource: 'prestashop',
                providerPaymentId: $providerPaymentId,
                amount: (string) $payment->amount,
                currency: (string) $payment->currency,
                paidAmountBefore: $before,
                paidAmountAfter: $state['paid_amount'],
                paymentState: $paymentState,
                accountedAt: $accountedAt->toIso8601String(),
            );

            return PrestashopOrderPayment::STATUS_ACCOUNTED;
        }, 3);
    }

    private function matchingPayNowPayment(PrestashopOrderPayment $payment, int $orderId): ?PayNowPayment
    {
        if (blank($payment->transaction_id)) {
            return null;
        }

        return PayNowPayment::query()
            ->where('order_id', $orderId)
            ->where('provider_payment_id', $payment->transaction_id)
            ->where('status', PayNowPaymentStatus::Confirmed->value)
            ->whereNotNull('accounted_at')
            ->first();
    }

    private function markForReview(
        PrestashopOrderPayment $payment,
        string $code,
        string $message,
    ): string {
        $payment->forceFill([
            'accounting_status' => PrestashopOrderPayment::STATUS_REVIEW,
            'needs_review' => true,
            'safe_error_code' => $code,
            'safe_error_message' => $message,
        ])->save();

        $payment->orderLink?->order?->events()->create([
            'event_type' => 'prestashop_payment_review_required',
            'title' => 'Płatność PrestaShop wymaga sprawdzenia',
            'description' => $message,
            'payload' => [
                'prestashop_order_payment_id' => $payment->getKey(),
                'external_payment_id' => $payment->external_payment_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'reason' => $code,
            ],
        ]);

        return PrestashopOrderPayment::STATUS_REVIEW;
    }
}
