<?php

namespace Modules\Integrations\PayNow\Services;

use Carbon\CarbonImmutable;
use JsonException;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowWebhookException;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Throwable;

class PayNowWebhookService
{
    public const MAXIMUM_BODY_BYTES = 65536;

    public function __construct(
        private readonly PayNowSignatureService $signature,
        private readonly PayNowPaymentStatusService $statuses,
    ) {}

    public function handle(string $rawBody, string $signature): void
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAXIMUM_BODY_BYTES) {
            throw new PayNowWebhookException('Invalid PayNow notification body.', 413);
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PayNowWebhookException('Invalid PayNow notification JSON.', 400);
        }

        if (! is_array($payload)) {
            throw new PayNowWebhookException('Invalid PayNow notification payload.', 400);
        }

        $externalId = $this->requiredString($payload, 'externalId', 100);
        $account = PayNowPayment::query()
            ->where('external_id', $externalId)
            ->with('account')
            ->first()?->account
            ?? PayNowAccount::query()
                ->where('account_key', PayNowAccount::DEFAULT_ACCOUNT_KEY)
                ->first();

        if (! $account?->hasCompleteCredentials()) {
            throw new PayNowWebhookException('PayNow notification verification is unavailable.', 503);
        }

        if (! $this->signature->verifyNotification(
            $rawBody,
            (string) $account->signature_key,
            $signature,
        )) {
            throw new PayNowWebhookException('Invalid PayNow notification signature.', 401);
        }

        $providerPaymentId = $this->requiredString($payload, 'paymentId', 64);
        $statusValue = $this->requiredString($payload, 'status', 20);
        $modifiedAtValue = $this->requiredString($payload, 'modifiedAt', 60);
        $status = PayNowPaymentStatus::tryFrom($statusValue);

        if (! $status) {
            throw new PayNowWebhookException('Unknown PayNow payment status.', 400);
        }

        try {
            $modifiedAt = CarbonImmutable::parse($modifiedAtValue);
        } catch (Throwable) {
            throw new PayNowWebhookException('Invalid PayNow modification time.', 400);
        }

        $this->statuses->recordWebhook(
            $providerPaymentId,
            $externalId,
            $status,
            $modifiedAt,
            $payload,
            $rawBody,
        );
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $maximumLength): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maximumLength) {
            throw new PayNowWebhookException('Invalid PayNow notification field.', 400);
        }

        return trim($value);
    }
}
