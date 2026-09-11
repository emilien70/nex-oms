<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Throwable;

final class KsefSubmissionRecoveryPolicy
{
    /** @return array{decision: string, reason: string} */
    public function classify(KsefInvoiceSubmission $submission, bool $hasUpo, bool $integrityValid, CarbonImmutable $now): array
    {
        $result = fn (string $decision, string $reason): array => compact('decision', 'reason');

        try {
            if ($submission->status->isTerminal()) {
                return $result('NO_ACTION', $submission->status === Status::Accepted && ! $hasUpo ? 'existing_upo_follow_up' : 'terminal_history');
            }
            if ($submission->status->allowsStatusRefresh()) {
                return $result('EXISTING_FOLLOW_UP', 'existing_status_follow_up');
            }
            if ($submission->status === Status::Uncertain) {
                return $result($integrityValid && filled($submission->session_reference_number) ? 'EXISTING_FOLLOW_UP' : 'REVIEW_REQUIRED', 'existing_uncertain_no_resend');
            }
            if ($submission->execution_protocol_version !== KsefSubmissionExecution::VERSION) {
                return $result('REVIEW_REQUIRED', 'legacy_or_unknown_protocol');
            }
            if (! $integrityValid || $hasUpo || $submission->execution_expires_at === null
                || $submission->recovery_code !== null || $submission->recovered_at !== null
                || $submission->invoice_reference_number !== null
                || $submission->ksef_number !== null || $submission->ksef_status_code !== null
                || ($submission->execution_owner !== null && preg_match('/^[a-f0-9]{64}$/D', $submission->execution_owner) !== 1)
                || ($submission->status === Status::SessionOpened && (blank($submission->session_reference_number) || $submission->execution_owner === null))
                || ($submission->status === Status::Preparing && ($submission->session_reference_number !== null || $submission->invoice_post_started_at !== null))
                || ($submission->invoice_post_started_at !== null && ($submission->invoice_post_started_at->gt($submission->execution_expires_at) || $submission->invoice_post_started_at->gt($now)))) {
                return $result('REVIEW_REQUIRED', 'execution_evidence_inconsistent');
            }
            if ($submission->execution_expires_at->gt($now)) {
                return $result('ACTIVE', 'not_stale');
            }

            return $submission->invoice_post_started_at === null
                ? $result('BEFORE_POST', 'ksef_submission_interrupted_before_post')
                : $result('POST_MAY_HAVE_OCCURRED', 'ksef_submission_interrupted_after_post_boundary');
        } catch (Throwable) {
            return $result('REVIEW_REQUIRED', 'execution_evidence_unreadable');
        }
    }
}
