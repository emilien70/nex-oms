<?php

namespace Modules\Integrations\PayNow\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PayNowCustomerPaymentService
{
    public function __construct(
        private readonly PayNowClient $client,
        private readonly PayNowPaymentStatusService $statuses,
        private readonly PayNowPaymentLinkService $links,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    public function check(PayNowPayment $payment, ?string $providerHint): array
    {
        if ($providerHint !== null && $providerHint !== $payment->provider_payment_id) {
            return $this->present($payment);
        }

        if ($payment->status === PayNowPaymentStatus::Confirmed) {
            return $this->present($payment, true);
        }

        $verified = $this->verify($payment);

        return $verified ? $this->present($verified, true) : $this->present($payment->refresh(), unavailable: true);
    }

    public function present(PayNowPayment $payment, bool $verified = false, bool $unavailable = false): array
    {
        $order = $payment->order;
        $state = 'pending';
        $title = 'Oczekujemy na potwierdzenie płatności';
        $message = 'Nie zlecaj kolejnej wpłaty, dopóki trwa potwierdzanie tej płatności.';
        $canRetry = false;

        if ($payment->status === PayNowPaymentStatus::Confirmed) {
            $state = 'confirmed';
            $title = 'Płatność została dokonana';
            $message = 'Dziękujemy za dokonanie płatności.';
        } elseif ($order && $this->decimal->compare($order->paid_amount, $order->total_gross) >= 0) {
            $state = 'settled';
            $title = 'Zamówienie zostało już opłacone';
            $message = 'Nie ma potrzeby ponawiania płatności.';
        } elseif (! $order || $order->status === Order::STATUS_CANCELLED) {
            $state = 'unavailable';
            $title = 'Płatność nie jest dostępna';
            $message = 'Skontaktuj się ze sprzedawcą.';
        } elseif ($unavailable) {
            $state = 'unavailable';
            $title = 'Nie możemy teraz potwierdzić płatności';
            $message = 'Spróbuj sprawdzić status za chwilę. Nie zlecaj ponownie wpłaty, jeśli środki zostały pobrane.';
        } elseif ($verified && in_array($payment->status, [
            PayNowPaymentStatus::Rejected, PayNowPaymentStatus::Error, PayNowPaymentStatus::Expired,
        ], true)) {
            $state = 'failed';
            $title = 'Płatność nie została zrealizowana';
            $message = 'Możesz spróbować ponownie.';
            $canRetry = ! $payment->needs_review && $payment->account?->is_active === true;
        }

        return [
            'state' => $state,
            'title' => $title,
            'message' => $message,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'transactionId' => $state === 'confirmed' ? $payment->provider_payment_id : null,
            'canRetry' => $canRetry,
            'poll' => ! in_array($state, ['confirmed', 'settled', 'failed'], true),
            'isTest' => $payment->account?->environment === PayNowEnvironment::Sandbox,
        ];
    }

    public function retry(PayNowPayment $source, ?string $providerHint): string
    {
        if (! $source->order_id || ($providerHint !== null && $providerHint !== $source->provider_payment_id)) {
            $this->denyRetry();
        }

        $lock = Cache::lock('paynow-customer-retry-'.$source->order_id, 60);
        if (! $lock->get()) {
            $this->denyRetry();
        }

        try {
            $latest = PayNowPayment::query()->where('order_id', $source->order_id)->latest('id')->firstOrFail();
            $latest = $this->verify($latest);
            if (! $latest) {
                $this->denyRetry();
            }
            $state = $this->present($latest, true);

            if ($latest->needs_review || in_array($state['state'], ['confirmed', 'settled', 'unavailable'], true)) {
                $this->denyRetry();
            }

            $result = $this->links->createOrReuseForCustomer($latest);
            $url = (string) $result->payment->payment_url;
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (($parts['scheme'] ?? null) !== 'https'
                || ! ($host === 'paynow.pl' || str_ends_with($host, '.paynow.pl'))
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== 443)) {
                $this->denyRetry();
            }

            return $url;
        } finally {
            $lock->release();
        }
    }

    private function verify(PayNowPayment $payment): ?PayNowPayment
    {
        if ($payment->status === PayNowPaymentStatus::Confirmed) {
            return $payment;
        }
        if (! $payment->provider_payment_id || ! $payment->account?->hasCompleteCredentials()) {
            return null;
        }

        try {
            $result = $this->client->getPaymentStatus($payment->account, $payment->provider_payment_id);

            return $this->statuses->recordStatusCheck($payment, $result['status'], $result['payload'], $result['raw_body']);
        } catch (PayNowApiException) {
            return null;
        }
    }

    private function denyRetry(): never
    {
        throw ValidationException::withMessages([
            'payment_link' => 'Nie można teraz ponowić płatności. Sprawdź jej status ponownie lub skontaktuj się ze sprzedawcą.',
        ]);
    }
}
