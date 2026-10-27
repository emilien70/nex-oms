<?php

namespace Modules\Integrations\PayNow\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\ValueObjects\PayNowPaymentLinkResult;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PayNowPaymentLinkService
{
    private const MINIMUM_AMOUNT = '1.00';

    private const MAXIMUM_AMOUNT = '1000000.00';

    public function __construct(
        private readonly PayNowAccountService $accountService,
        private readonly PayNowClient $client,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly PayNowReturnUrlService $returnUrls,
    ) {}

    public function createOrReuse(Order $order): PayNowPaymentLinkResult
    {
        return $this->create($order);
    }

    public function createOrReuseForCustomer(PayNowPayment $verifiedPayment, ?callable $authorize = null): PayNowPaymentLinkResult
    {
        return $this->create($verifiedPayment->order()->firstOrFail(), $verifiedPayment, $authorize);
    }

    private function create(Order $order, ?PayNowPayment $verifiedPayment = null, ?callable $authorize = null): PayNowPaymentLinkResult
    {
        $account = $this->accountService->get();
        $payment = $this->prepare($order, $account, $verifiedPayment, $authorize);
        if ($authorize) {
            DB::transaction(fn () => $authorize($payment), 3);
        }

        if ($this->hasUsableLink($payment)) {
            return new PayNowPaymentLinkResult($payment, true);
        }

        try {
            $response = $this->client->createPaymentLink(
                $payment->account()->firstOrFail(),
                $this->requestPayload($payment),
                $payment->idempotency_key,
            );
        } catch (PayNowApiException $exception) {
            $this->recordFailure($payment, $exception);

            throw $exception;
        }

        return $this->recordSuccess($payment, $response);
    }

    private function prepare(Order $order, PayNowAccount $account, ?PayNowPayment $verifiedPayment, ?callable $authorize): PayNowPayment
    {
        return DB::transaction(function () use ($order, $account, $verifiedPayment, $authorize): PayNowPayment {
            if ($authorize) {
                $authorize();
            }
            $managedAccount = PayNowAccount::query()->lockForUpdate()->findOrFail($account->getKey());
            $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $managedOrder->assertPurchaseReady();

            if ($managedOrder->status === Order::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['payment_link' => 'Nie można opłacić anulowanego zamówienia.']);
            }

            $this->assertAccountIsReady($managedAccount);

            $currency = strtoupper(trim((string) $managedOrder->currency));
            if ($currency !== 'PLN') {
                throw ValidationException::withMessages([
                    'payment_link' => 'Linki PayNow są obecnie dostępne wyłącznie dla zamówień w PLN.',
                ]);
            }

            $email = trim((string) $managedOrder->customer_email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 50) {
                throw ValidationException::withMessages([
                    'payment_link' => 'Uzupełnij prawidłowy adres e-mail klienta, nie dłuższy niż 50 znaków.',
                ]);
            }

            $amount = $this->decimal->subtract(
                (string) $managedOrder->total_gross,
                (string) $managedOrder->paid_amount,
            );

            if ($this->decimal->compare($amount, self::MINIMUM_AMOUNT) < 0) {
                throw ValidationException::withMessages([
                    'payment_link' => 'Do zapłaty musi pozostać co najmniej 1,00 PLN.',
                ]);
            }

            if ($this->decimal->compare($amount, self::MAXIMUM_AMOUNT) > 0) {
                throw ValidationException::withMessages([
                    'payment_link' => 'Kwota do zapłaty przekracza limit PayNow wynoszący 1 000 000,00 PLN.',
                ]);
            }

            $description = trim(str_replace(
                '[id_zamowienia]',
                (string) $managedOrder->getKey(),
                (string) $managedAccount->description_template,
            ));

            $existing = PayNowPayment::query()
                ->where('paynow_account_id', $managedAccount->getKey())
                ->where('order_id', $managedOrder->getKey())
                ->where('amount', $amount)
                ->where('currency', $currency)
                ->where('buyer_email_snapshot', $email)
                ->where('description', $description)
                ->whereIn('status', [
                    PayNowPaymentStatus::Created->value,
                    PayNowPaymentStatus::Pending->value,
                ])
                ->where('valid_until', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($verifiedPayment !== null) {
                $this->assertCustomerRetry($managedOrder, $managedAccount, $verifiedPayment, $existing);
            }

            if ($existing) {
                return $existing;
            }

            return PayNowPayment::query()->create([
                'paynow_account_id' => $managedAccount->getKey(),
                'order_id' => $managedOrder->getKey(),
                'amount' => $amount,
                'currency' => $currency,
                'external_id' => 'nex-order-'.$managedOrder->getKey().'-'.Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'status' => PayNowPaymentStatus::Created,
                'buyer_email_snapshot' => $email,
                'description' => $description,
                'valid_until' => now()->addSeconds($managedAccount->link_validity_seconds),
            ]);
        });
    }

    private function assertAccountIsReady(PayNowAccount $account): void
    {
        if (! $account->is_active
            || ! $account->hasCompleteCredentials()
            || $account->last_test_status !== PayNowConnectionTestStatus::Success) {
            throw ValidationException::withMessages([
                'payment_link' => 'Integracja PayNow nie jest aktywna. Sprawdź jej konfigurację.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function requestPayload(PayNowPayment $payment): array
    {
        $account = $payment->account;
        $payload = [
            'amount' => $this->minorUnits($payment->amount),
            'currency' => $payment->currency,
            'externalId' => $payment->external_id,
            'description' => $payment->description,
            'buyer' => [
                'email' => $payment->buyer_email_snapshot,
            ],
            'validityTime' => (int) $account->link_validity_seconds,
        ];

        $customReturn = $account->continue_url;
        $payload['continueUrl'] = $this->returnUrls->isDefaultReturn($customReturn)
            ? $this->returnUrls->forPayment($payment)
            : $customReturn;

        return $payload;
    }

    private function assertCustomerRetry(Order $order, PayNowAccount $account, PayNowPayment $verified, ?PayNowPayment $existing): void
    {
        $payments = PayNowPayment::query()->where('order_id', $order->getKey())->orderByDesc('id')->lockForUpdate()->get();
        $latest = $payments->first();
        $unsafe = $payments->contains(fn (PayNowPayment $payment): bool => $payment->needs_review
            || ($payment->status === PayNowPaymentStatus::Confirmed && $payment->accounted_at === null)
            || (! $payment->is($latest) && in_array($payment->status, [
                PayNowPaymentStatus::Created, PayNowPaymentStatus::Pending, PayNowPaymentStatus::Abandoned,
            ], true)));

        if (! $latest?->is($verified)
            || $latest->paynow_account_id !== $account->getKey()
            || $latest->status !== $verified->status
            || $latest->provider_payment_id !== $verified->provider_payment_id
            || $unsafe
            || (! $existing?->is($latest) && ! in_array($latest->status, [
                PayNowPaymentStatus::Rejected, PayNowPaymentStatus::Error, PayNowPaymentStatus::Expired,
            ], true))) {
            throw ValidationException::withMessages([
                'payment_link' => 'Nie można teraz ponowić płatności. Sprawdź jej status ponownie lub skontaktuj się ze sprzedawcą.',
            ]);
        }
    }

    private function minorUnits(string $amount): int
    {
        $normalized = $this->decimal->normalize($amount, 2);
        [$whole, $fraction] = explode('.', $normalized, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function hasUsableLink(PayNowPayment $payment): bool
    {
        return $payment->provider_payment_id !== null
            && $payment->payment_url !== null
            && $payment->valid_until?->isFuture()
            && in_array($payment->status, [
                PayNowPaymentStatus::Created,
                PayNowPaymentStatus::Pending,
            ], true);
    }

    private function recordFailure(PayNowPayment $payment, PayNowApiException $exception): void
    {
        DB::transaction(function () use ($payment, $exception): void {
            PayNowPayment::query()
                ->lockForUpdate()
                ->findOrFail($payment->getKey())
                ->forceFill([
                    'safe_error_code' => mb_substr($exception->safeCode ?? 'provider_error', 0, 100),
                    'safe_error_message' => mb_substr($exception->getMessage(), 0, 1000),
                ])
                ->save();
        });
    }

    /**
     * @param  array{url: string, payment_id: string, status: PayNowPaymentStatus, payload: array<string, mixed>, raw_body: string}  $response
     */
    private function recordSuccess(PayNowPayment $payment, array $response): PayNowPaymentLinkResult
    {
        return DB::transaction(function () use ($payment, $response): PayNowPaymentLinkResult {
            $managed = PayNowPayment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($this->hasUsableLink($managed)) {
                return new PayNowPaymentLinkResult($managed, true);
            }

            // A webhook can confirm the payment while the link request is still in flight.
            $confirmed = $managed->status === PayNowPaymentStatus::Confirmed;
            $idConflict = $confirmed && $managed->provider_payment_id !== $response['payment_id'];
            $managed->forceFill([
                'provider_payment_id' => $confirmed ? $managed->provider_payment_id : $response['payment_id'],
                'payment_url' => $idConflict ? $managed->payment_url : $response['url'],
                'status' => $confirmed ? PayNowPaymentStatus::Confirmed : $response['status'],
            ] + ($idConflict ? [
                'needs_review' => true,
                'safe_error_code' => 'provider_id_changed_after_confirmation',
                'safe_error_message' => 'Odpowiedź tworzenia linku wskazuje inną, już potwierdzoną płatność.',
            ] : ($managed->needs_review ? [] : [
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])))->save();

            $payloadHash = hash('sha256', $response['raw_body']);
            $managed->events()->firstOrCreate([
                'event_key' => hash('sha256', implode('|', [
                    'api_response',
                    $managed->external_id,
                    $response['payment_id'],
                    $response['status']->value,
                    $payloadHash,
                ])),
            ], [
                'source' => 'api_response',
                'provider_payment_id' => $response['payment_id'],
                'external_id' => $managed->external_id,
                'status' => $response['status'],
                'received_at' => now(),
                'payload' => $response['payload'],
                'payload_sha256' => $payloadHash,
            ]);

            $managed->order?->events()->create([
                'event_type' => 'paynow_payment_link_created',
                'title' => 'Utworzono link płatności PayNow',
                'description' => 'Kwota: '.$managed->amount.' '.$managed->currency,
                'payload' => [
                    'paynow_payment_id' => $managed->getKey(),
                    'provider_payment_id' => $response['payment_id'],
                    'amount' => $managed->amount,
                    'currency' => $managed->currency,
                ],
            ]);

            return new PayNowPaymentLinkResult($managed->refresh(), false);
        });
    }
}
