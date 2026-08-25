<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefSubmissionFollowUpPolicy
{
    public const ACTION_RECONCILE = 'reconcile';

    public const ACTION_STATUS = 'status';

    public const ACTION_UPO = 'upo';

    public function action(KsefInvoiceSubmission $submission, bool $hasUpo): ?string
    {
        return match ($submission->status) {
            KsefInvoiceSubmissionStatus::Submitted,
            KsefInvoiceSubmissionStatus::Processing => self::ACTION_STATUS,
            KsefInvoiceSubmissionStatus::Accepted => $hasUpo ? null : self::ACTION_UPO,
            KsefInvoiceSubmissionStatus::Uncertain => self::ACTION_RECONCILE,
            default => null,
        };
    }

    public function nextAttemptAt(
        int $completedAttempts,
        ?int $retryAfterSeconds = null,
        ?CarbonImmutable $now = null,
    ): CarbonImmutable {
        $delays = collect(config('ksef.follow_up.backoff_seconds', [60, 300, 900, 3600]))
            ->map(fn (mixed $seconds): int => max(1, (int) $seconds))
            ->values();
        $delay = $delays->get(
            min(max(0, $completedAttempts), max(0, $delays->count() - 1)),
            3600,
        );

        return ($now ?? CarbonImmutable::now())
            ->addSeconds(max($delay, max(0, (int) $retryAfterSeconds)));
    }

    public function isTransient(KsefApiException $exception): bool
    {
        return in_array($exception->safeCode, [
            'network_error',
            'rate_limited',
            'ksef_upo_not_available',
            'ksef_reconciliation_result_unresolved',
            'ksef_follow_up_in_progress',
        ], true)
            || $exception->httpStatus === 429
            || ($exception->httpStatus !== null && $exception->httpStatus >= 500);
    }
}
