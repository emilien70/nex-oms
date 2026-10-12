<?php

namespace Modules\Integrations\PayNow\Services;

use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowPayment;

class PayNowReconciliationService
{
    private const BATCH_SIZE = 20;

    private const RETRY_AFTER_MINUTES = 2;

    public function __construct(
        private readonly PayNowClient $client,
        private readonly PayNowPaymentStatusService $statuses,
    ) {}

    public function reconcileDue(): int
    {
        $payments = PayNowPayment::query()
            ->with('account')
            ->whereNotNull('provider_payment_id')
            ->whereIn('status', [
                PayNowPaymentStatus::Created->value,
                PayNowPaymentStatus::Pending->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('last_status_checked_at')
                    ->orWhere('last_status_checked_at', '<=', now()->subMinutes(self::RETRY_AFTER_MINUTES));
            })
            ->oldest('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($payments as $payment) {
            $account = $payment->account;

            if (! $account?->hasCompleteCredentials()) {
                $this->recordFailure(
                    $payment,
                    'credentials_missing',
                    'Nie można sprawdzić płatności bez zapisanych danych dostępowych PayNow.',
                    true,
                );

                continue;
            }

            try {
                $result = $this->client->getPaymentStatus(
                    $account,
                    (string) $payment->provider_payment_id,
                );

                $this->statuses->recordStatusCheck(
                    $payment,
                    $result['status'],
                    $result['payload'],
                    $result['raw_body'],
                );
            } catch (PayNowApiException $exception) {
                $this->recordFailure(
                    $payment,
                    $exception->safeCode ?? 'provider_error',
                    $exception->getMessage(),
                    false,
                );
            }
        }

        return $payments->count();
    }

    private function recordFailure(
        PayNowPayment $payment,
        string $code,
        string $message,
        bool $needsReview,
    ): void {
        PayNowPayment::query()
            ->whereKey($payment->getKey())
            ->update([
                'last_status_checked_at' => now(),
                'needs_review' => $needsReview || $payment->needs_review,
                'safe_error_code' => mb_substr($code, 0, 100),
                'safe_error_message' => mb_substr($message, 0, 1000),
                'updated_at' => now(),
            ]);
    }
}
