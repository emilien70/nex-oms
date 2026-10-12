<?php

namespace Modules\Integrations\PayNow\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Models\PayNowPaymentEvent;

class PayNowPaymentStatusService
{
    public function __construct(
        private readonly PayNowPaymentAccountingService $accounting,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordWebhook(
        string $providerPaymentId,
        string $externalId,
        PayNowPaymentStatus $status,
        CarbonInterface $modifiedAt,
        array $payload,
        string $rawBody,
    ): ?PayNowPayment {
        $payment = $this->record(
            source: 'webhook',
            providerPaymentId: $providerPaymentId,
            externalId: $externalId,
            status: $status,
            modifiedAt: $modifiedAt,
            payload: $payload,
            rawBody: $rawBody,
        );

        return $this->accountIfConfirmed($payment);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordStatusCheck(
        PayNowPayment $payment,
        PayNowPaymentStatus $status,
        array $payload,
        string $rawBody,
    ): PayNowPayment {
        $recorded = $this->record(
            source: 'status_check',
            providerPaymentId: (string) $payment->provider_payment_id,
            externalId: $payment->external_id,
            status: $status,
            modifiedAt: null,
            payload: $payload,
            rawBody: $rawBody,
            knownPaymentId: (int) $payment->getKey(),
        );

        return $this->accountIfConfirmed($recorded) ?? $payment->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        string $source,
        string $providerPaymentId,
        string $externalId,
        PayNowPaymentStatus $status,
        ?CarbonInterface $modifiedAt,
        array $payload,
        string $rawBody,
        ?int $knownPaymentId = null,
    ): ?PayNowPayment {
        return DB::transaction(function () use (
            $source,
            $providerPaymentId,
            $externalId,
            $status,
            $modifiedAt,
            $payload,
            $rawBody,
            $knownPaymentId,
        ): ?PayNowPayment {
            $payment = $knownPaymentId === null
                ? PayNowPayment::query()->where('external_id', $externalId)->lockForUpdate()->first()
                : PayNowPayment::query()->whereKey($knownPaymentId)->lockForUpdate()->first();

            $providerOwner = PayNowPayment::query()
                ->where('provider_payment_id', $providerPaymentId)
                ->lockForUpdate()
                ->first();

            $eventKey = hash('sha256', implode('|', [
                $source,
                $providerPaymentId,
                $externalId,
                $status->value,
                $modifiedAt?->toIso8601String() ?? '',
            ]));

            $event = PayNowPaymentEvent::query()->firstOrCreate([
                'event_key' => $eventKey,
            ], [
                'paynow_payment_id' => $payment?->getKey(),
                'source' => $source,
                'provider_payment_id' => $providerPaymentId,
                'external_id' => $externalId,
                'status' => $status,
                'provider_modified_at' => $modifiedAt,
                'received_at' => now(),
                'payload' => $payload,
                'payload_sha256' => hash('sha256', $rawBody),
            ]);

            if (! $payment) {
                return null;
            }

            if ($providerOwner && ! $providerOwner->is($payment)) {
                $payment->forceFill([
                    'needs_review' => true,
                    'safe_error_code' => 'provider_id_conflict',
                    'safe_error_message' => 'Identyfikator płatności PayNow wskazuje inną próbę płatności.',
                    'last_status_checked_at' => $source === 'status_check' ? now() : $payment->last_status_checked_at,
                ])->save();

                return $payment;
            }

            if (! $event->wasRecentlyCreated) {
                return $payment;
            }

            $changes = [];
            $currentStatus = $payment->status;
            $currentModifiedAt = $payment->provider_modified_at;
            $isOlder = $modifiedAt !== null
                && $currentModifiedAt !== null
                && $modifiedAt->lt($currentModifiedAt);
            $isSameMoment = $modifiedAt !== null
                && $currentModifiedAt !== null
                && $modifiedAt->equalTo($currentModifiedAt);

            if ($payment->provider_payment_id === null
                || ($payment->provider_payment_id !== $providerPaymentId
                    && $currentStatus !== PayNowPaymentStatus::Confirmed)) {
                $changes['provider_payment_id'] = $providerPaymentId;
            } elseif ($payment->provider_payment_id !== $providerPaymentId) {
                $changes += $this->reviewChanges(
                    'provider_id_changed_after_confirmation',
                    'PayNow przesłał inny identyfikator dla już potwierdzonej płatności.',
                );
            }

            if ($currentStatus === PayNowPaymentStatus::Confirmed) {
                if ($status !== PayNowPaymentStatus::Confirmed && ! $isOlder) {
                    $changes += $this->reviewChanges(
                        'status_changed_after_confirmation',
                        'PayNow przesłał nowy status dla wcześniej potwierdzonej płatności.',
                    );
                }
            } elseif ($status === PayNowPaymentStatus::Confirmed) {
                $changes['status'] = $status;
                $changes['confirmed_at'] = $payment->confirmed_at ?? $modifiedAt ?? now();
            } elseif ($isSameMoment && $currentStatus !== $status) {
                $changes += $this->reviewChanges(
                    'ambiguous_provider_status',
                    'PayNow przesłał sprzeczne statusy z tym samym czasem modyfikacji.',
                );
            } elseif (! $isOlder) {
                $changes['status'] = $status;
            }

            if ($modifiedAt !== null && ($currentModifiedAt === null || $modifiedAt->gt($currentModifiedAt))) {
                $changes['provider_modified_at'] = $modifiedAt;
            }

            if ($source === 'status_check') {
                $changes['last_status_checked_at'] = now();
            }

            if ($changes !== []) {
                $payment->forceFill($changes)->save();
            }

            return $payment;
        }, 3);
    }

    private function accountIfConfirmed(?PayNowPayment $payment): ?PayNowPayment
    {
        if (! $payment || $payment->status !== PayNowPaymentStatus::Confirmed) {
            return $payment;
        }

        return $this->accounting->account($payment);
    }

    /** @return array{needs_review: bool, safe_error_code: string, safe_error_message: string} */
    private function reviewChanges(string $code, string $message): array
    {
        return [
            'needs_review' => true,
            'safe_error_code' => $code,
            'safe_error_message' => $message,
        ];
    }
}
