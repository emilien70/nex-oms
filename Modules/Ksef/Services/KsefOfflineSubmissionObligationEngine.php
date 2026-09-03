<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationReason;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\ValueObjects\KsefLatarniaFailureEvent;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligation;

final class KsefOfflineSubmissionObligationEngine
{
    private const LEGAL_TIMEZONE = 'Europe/Warsaw';

    public function __construct(
        private readonly PolishBusinessDayCalendar $calendar,
        private readonly KsefLatarniaFailureEventProjector $events,
    ) {}

    /**
     * @param  iterable<KsefLatarniaMessage>  $messages
     * @param  iterable<KsefInvoiceSubmission>  $submissions
     */
    public function evaluate(
        KsefOfflineIssuance $issuance,
        iterable $messages,
        iterable $submissions,
        CarbonImmutable $asOf,
        KsefLatarniaEvidenceCoverage $coverage,
    ): KsefOfflineSubmissionObligation {
        $evaluatedAt = $asOf->utc();
        $baseDeadline = $this->calendar->nextBusinessDayAfter($issuance->issue_date);
        [$latestSubmission, $submissionIntegrityError] = $this->latestSubmission($issuance, $submissions);

        if ($submissionIntegrityError) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::SubmissionIntegrityFailure,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            );
        }

        $submissionResult = $this->submissionResult(
            $latestSubmission,
            $baseDeadline,
            $coverage,
            $evaluatedAt,
        );

        if ($submissionResult !== null) {
            return $submissionResult;
        }

        if ($issuance->procedure !== KsefOfflineIssuanceProcedure::Offline24) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::EvidenceUnavailable,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::UnsupportedProcedure,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            );
        }

        $latarniaEnvironment = $this->latarniaEnvironment($issuance->environment);

        if ($latarniaEnvironment === null
            || $coverage === KsefLatarniaEvidenceCoverage::UnsupportedEnvironment) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::EvidenceUnavailable,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::LatarniaUnsupportedEnvironment,
                KsefLatarniaEvidenceCoverage::UnsupportedEnvironment,
                $latestSubmission?->status,
                $evaluatedAt,
            );
        }

        if ($coverage !== KsefLatarniaEvidenceCoverage::Complete) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::EvidenceUnavailable,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::LatarniaEvidenceInsufficient,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            );
        }

        $projection = $this->events->project($messages, $latarniaEnvironment);

        if ($projection->isAmbiguous()) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::AmbiguousLatarniaHistory,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
                $projection->ambiguousEventIds,
                $projection->ambiguousMessageIds,
            );
        }

        $publishedAfterIssuance = array_values(array_filter(
            $projection->events,
            fn (KsefLatarniaFailureEvent $event): bool => $event->publishedAt->greaterThan($issuance->issued_at)
                && ! $event->publishedAt->greaterThan($evaluatedAt),
        ));
        $totalFailure = collect($publishedAfterIssuance)
            ->first(fn (KsefLatarniaFailureEvent $event): bool => $event->category === KsefLatarniaMessageCategory::TotalFailure);

        if ($totalFailure !== null) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::TotalFailureNoSubmission,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
                [$totalFailure->eventId],
                $totalFailure->messageIds,
                totalFailureEventId: $totalFailure->eventId,
            );
        }

        $ordinaryFailures = array_values(array_filter(
            $publishedAfterIssuance,
            fn (KsefLatarniaFailureEvent $event): bool => $event->category === KsefLatarniaMessageCategory::Failure,
        ));

        return $this->evaluateActiveDeadline(
            $baseDeadline,
            $ordinaryFailures,
            $coverage,
            $latestSubmission?->status,
            $evaluatedAt,
        );
    }

    /**
     * @param  iterable<KsefInvoiceSubmission>  $submissions
     * @return array{0: ?KsefInvoiceSubmission, 1: bool}
     */
    private function latestSubmission(KsefOfflineIssuance $issuance, iterable $submissions): array
    {
        $linked = collect($submissions)
            ->filter(fn (mixed $submission): bool => $submission instanceof KsefInvoiceSubmission
                && (int) $submission->offline_issuance_id === (int) $issuance->getKey())
            ->values();

        foreach ($linked as $submission) {
            if ((int) $submission->invoice_id !== (int) $issuance->invoice_id
                || $submission->environment !== $issuance->environment
                || ! is_int($submission->attempt_number)
                || $submission->attempt_number < 1) {
                return [$submission, true];
            }
        }

        if ($linked->pluck('attempt_number')->duplicates()->isNotEmpty()) {
            return [$linked->sortByDesc('attempt_number')->first(), true];
        }

        return [$linked->sortByDesc('attempt_number')->first(), false];
    }

    private function submissionResult(
        ?KsefInvoiceSubmission $submission,
        CarbonImmutable $baseDeadline,
        KsefLatarniaEvidenceCoverage $coverage,
        CarbonImmutable $evaluatedAt,
    ): ?KsefOfflineSubmissionObligation {
        if ($submission === null) {
            return null;
        }

        $hasReference = is_string($submission->invoice_reference_number)
            && trim($submission->invoice_reference_number) !== '';

        if ($submission->status === KsefInvoiceSubmissionStatus::Accepted) {
            if ($submission->invoicing_mode !== KsefInvoicingMode::Offline) {
                return $this->result(
                    KsefOfflineSubmissionObligationStatus::TransportModeMismatch,
                    $baseDeadline,
                    null,
                    KsefOfflineSubmissionObligationReason::TransportModeMismatch,
                    $coverage,
                    $submission->status,
                    $evaluatedAt,
                );
            }

            if (! $hasReference) {
                return $this->submissionIntegrityResult($submission, $baseDeadline, $coverage, $evaluatedAt);
            }

            return $this->result(
                KsefOfflineSubmissionObligationStatus::Fulfilled,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::SubmissionAlreadyAccepted,
                $coverage,
                $submission->status,
                $evaluatedAt,
            );
        }

        if (in_array($submission->status, [
            KsefInvoiceSubmissionStatus::Submitted,
            KsefInvoiceSubmissionStatus::Processing,
        ], true)) {
            if (! $hasReference) {
                return $this->submissionIntegrityResult($submission, $baseDeadline, $coverage, $evaluatedAt);
            }

            return $this->result(
                KsefOfflineSubmissionObligationStatus::SubmittedPendingResult,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::SubmissionPendingResult,
                $coverage,
                $submission->status,
                $evaluatedAt,
            );
        }

        if ($submission->status === KsefInvoiceSubmissionStatus::Uncertain) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::TransmissionUncertain,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::TransmissionUncertain,
                $coverage,
                $submission->status,
                $evaluatedAt,
            );
        }

        if ($submission->status === KsefInvoiceSubmissionStatus::Rejected) {
            return $this->result(
                KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::SubmissionRejected,
                $coverage,
                $submission->status,
                $evaluatedAt,
            );
        }

        if ($hasReference) {
            return $this->submissionIntegrityResult($submission, $baseDeadline, $coverage, $evaluatedAt);
        }

        return null;
    }

    /**
     * @param  list<KsefLatarniaFailureEvent>  $failures
     */
    private function evaluateActiveDeadline(
        CarbonImmutable $baseDeadline,
        array $failures,
        KsefLatarniaEvidenceCoverage $coverage,
        ?KsefInvoiceSubmissionStatus $lastSubmissionStatus,
        CarbonImmutable $evaluatedAt,
    ): KsefOfflineSubmissionObligation {
        $effectiveDeadline = $baseDeadline;
        $appliedEvents = [];
        $appliedMessages = [];
        $lastFailure = null;
        $lastEndDate = null;

        foreach ($failures as $failure) {
            if ($lastFailure !== null) {
                if ($failure->publishedAt->equalTo($lastFailure->publishedAt)
                    || $lastFailure->endAt === null
                    || $failure->startAt->lessThan($lastFailure->endAt)) {
                    return $this->result(
                        KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory,
                        $baseDeadline,
                        null,
                        KsefOfflineSubmissionObligationReason::AmbiguousLatarniaHistory,
                        $coverage,
                        $lastSubmissionStatus,
                        $evaluatedAt,
                        [...$appliedEvents, $failure->eventId],
                        [...$appliedMessages, ...$failure->messageIds],
                    );
                }

                if ($failure->publishedAt->setTimezone(self::LEGAL_TIMEZONE)->toDateString()
                    > $effectiveDeadline->toDateString()) {
                    break;
                }
            }

            $appliedEvents[] = $failure->eventId;
            $appliedMessages = [...$appliedMessages, ...$failure->messageIds];

            if ($failure->endAt === null || $failure->endAt->greaterThan($evaluatedAt)) {
                return $this->result(
                    KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd,
                    $baseDeadline,
                    null,
                    count($appliedEvents) === 1
                        ? KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension
                        : KsefOfflineSubmissionObligationReason::SubsequentFailureReset,
                    $coverage,
                    $lastSubmissionStatus,
                    $evaluatedAt,
                    $appliedEvents,
                    array_values(array_unique($appliedMessages)),
                );
            }

            $lastEndDate = $failure->endAt->setTimezone(self::LEGAL_TIMEZONE)->startOfDay();
            $effectiveDeadline = $this->calendar->addBusinessDaysAfter($lastEndDate, 7);
            $lastFailure = $failure;
        }

        $reason = $appliedEvents === []
            ? KsefOfflineSubmissionObligationReason::Offline24Base
            : (count($appliedEvents) === 1
                ? KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension
                : KsefOfflineSubmissionObligationReason::SubsequentFailureReset);
        $localEvaluationDate = $evaluatedAt->setTimezone(self::LEGAL_TIMEZONE)->toDateString();
        $status = match (true) {
            $localEvaluationDate < $effectiveDeadline->toDateString() => KsefOfflineSubmissionObligationStatus::Pending,
            $localEvaluationDate === $effectiveDeadline->toDateString() => KsefOfflineSubmissionObligationStatus::DueToday,
            default => KsefOfflineSubmissionObligationStatus::Overdue,
        };

        return $this->result(
            $status,
            $baseDeadline,
            $effectiveDeadline,
            $reason,
            $coverage,
            $lastSubmissionStatus,
            $evaluatedAt,
            $appliedEvents,
            array_values(array_unique($appliedMessages)),
            $lastEndDate,
        );
    }

    private function submissionIntegrityResult(
        KsefInvoiceSubmission $submission,
        CarbonImmutable $baseDeadline,
        KsefLatarniaEvidenceCoverage $coverage,
        CarbonImmutable $evaluatedAt,
    ): KsefOfflineSubmissionObligation {
        return $this->result(
            KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError,
            $baseDeadline,
            null,
            KsefOfflineSubmissionObligationReason::SubmissionIntegrityFailure,
            $coverage,
            $submission->status,
            $evaluatedAt,
        );
    }

    private function latarniaEnvironment(KsefEnvironment $environment): ?KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => null,
        };
    }

    /**
     * @param  list<int>  $appliedEventIds
     * @param  list<string>  $appliedMessageIds
     */
    private function result(
        KsefOfflineSubmissionObligationStatus $status,
        CarbonImmutable $baseDeadline,
        ?CarbonImmutable $effectiveDeadline,
        KsefOfflineSubmissionObligationReason $reason,
        KsefLatarniaEvidenceCoverage $coverage,
        ?KsefInvoiceSubmissionStatus $lastSubmissionStatus,
        CarbonImmutable $evaluatedAt,
        array $appliedEventIds = [],
        array $appliedMessageIds = [],
        ?CarbonImmutable $ordinaryFailureEndDate = null,
        ?int $totalFailureEventId = null,
    ): KsefOfflineSubmissionObligation {
        return new KsefOfflineSubmissionObligation(
            $status,
            $baseDeadline,
            $effectiveDeadline,
            $reason,
            $coverage,
            array_values(array_unique($appliedEventIds)),
            array_values(array_unique($appliedMessageIds)),
            $lastSubmissionStatus,
            $evaluatedAt,
            $ordinaryFailureEndDate,
            $totalFailureEventId,
        );
    }
}
