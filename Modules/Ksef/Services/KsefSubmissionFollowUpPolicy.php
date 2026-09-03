<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefSubmissionFollowUpPolicy
{
    public const ACTION_RECONCILE = 'reconcile';

    public const ACTION_STATUS = 'status';

    public const ACTION_UPO = 'upo';

    public function action(KsefInvoiceSubmission $submission, bool $hasUpo): ?string
    {
        return $this->actionForStatus(
            $submission->status,
            $hasUpo,
            $submission->invoicing_mode,
        );
    }

    public function actionForStatus(
        KsefInvoiceSubmissionStatus $status,
        bool $hasUpo,
        ?KsefInvoicingMode $invoicingMode = null,
    ): ?string {
        return match ($status) {
            KsefInvoiceSubmissionStatus::Submitted,
            KsefInvoiceSubmissionStatus::Processing => self::ACTION_STATUS,
            KsefInvoiceSubmissionStatus::Accepted => $hasUpo
                || $invoicingMode === KsefInvoicingMode::Offline
                    ? null
                    : self::ACTION_UPO,
            KsefInvoiceSubmissionStatus::Uncertain => self::ACTION_RECONCILE,
            default => null,
        };
    }

    public function attemptsForAction(
        KsefInvoiceSubmission $submission,
        ?string $action,
    ): int {
        if ($action === null || $submission->follow_up_action !== $action) {
            return 0;
        }

        return max(0, $submission->follow_up_attempts);
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
