<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationReason;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\ValueObjects\KsefLatarniaFailureEvent;
use Modules\Ksef\ValueObjects\KsefLatarniaMaintenanceEvent;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligation;

final class KsefOfflineSubmissionObligationEngine
{
    private const LEGAL_TIMEZONE = 'Europe/Warsaw';

    public function __construct(
        private readonly PolishBusinessDayCalendar $calendar,
        private readonly KsefLatarniaFailureEventProjector $failures,
        private readonly KsefLatarniaMaintenanceEventProjector $maintenance,
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
        $baseDeadline = $issuance->procedure === KsefOfflineIssuanceProcedure::Offline24
            ? $this->calendar->nextBusinessDayAfter($issuance->issue_date)
            : null;
        [$latestSubmission, $submissionIntegrityError] = $this->latestSubmission($issuance, $submissions);

        if ($submissionIntegrityError) {
            return $this->result(
                $issuance,
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
            $issuance,
            $latestSubmission,
            $baseDeadline,
            $coverage,
            $evaluatedAt,
        );

        if ($submissionResult !== null) {
            return $submissionResult;
        }

        $latarniaEnvironment = $this->latarniaEnvironment($issuance->environment);

        if ($latarniaEnvironment === null
            || $coverage === KsefLatarniaEvidenceCoverage::UnsupportedEnvironment) {
            return $this->result(
                $issuance,
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
                $issuance,
                KsefOfflineSubmissionObligationStatus::EvidenceUnavailable,
                $baseDeadline,
                null,
                KsefOfflineSubmissionObligationReason::LatarniaEvidenceInsufficient,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            );
        }

        $knownMessages = collect($messages)
            ->filter(fn (mixed $message): bool => $message instanceof KsefLatarniaMessage
                && $message->source_environment === $latarniaEnvironment
                && ! $message->published_at->greaterThan($evaluatedAt)
                && ! $message->first_fetched_at->greaterThan($evaluatedAt))
            ->values();
        $failureProjection = $this->failures->project($knownMessages, $latarniaEnvironment);

        if ($failureProjection->isAmbiguous()) {
            return $this->ambiguousResult(
                $issuance,
                $baseDeadline,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
                $failureProjection->ambiguousEventIds,
                $failureProjection->ambiguousMessageIds,
            );
        }

        $eventsAfterIssuance = array_values(array_filter(
            $failureProjection->events,
            fn (KsefLatarniaFailureEvent $event): bool => $event->publishedAt->greaterThan($issuance->issued_at)
                && ! $event->publishedAt->greaterThan($evaluatedAt),
        ));
        $totalFailure = collect($eventsAfterIssuance)
            ->first(fn (KsefLatarniaFailureEvent $event): bool => $event->category === KsefLatarniaMessageCategory::TotalFailure);

        if ($totalFailure !== null) {
            return $this->result(
                $issuance,
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
            $eventsAfterIssuance,
            fn (KsefLatarniaFailureEvent $event): bool => $event->category === KsefLatarniaMessageCategory::Failure,
        ));

        return match ($issuance->procedure) {
            KsefOfflineIssuanceProcedure::Offline24 => $this->evaluateActiveDeadline(
                $issuance,
                $baseDeadline,
                $ordinaryFailures,
                KsefOfflineSubmissionObligationReason::Offline24Base,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            ),
            KsefOfflineIssuanceProcedure::PlannedUnavailability => $this->evaluatePlannedUnavailability(
                $issuance,
                $knownMessages,
                $ordinaryFailures,
                $latarniaEnvironment,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            ),
            KsefOfflineIssuanceProcedure::Failure => $this->evaluateFailure(
                $issuance,
                $knownMessages,
                $failureProjection->events,
                $latarniaEnvironment,
                $coverage,
                $latestSubmission?->status,
                $evaluatedAt,
            ),
        };
    }

    /**
     * @param  Collection<int, KsefLatarniaMessage>  $messages
     * @param  list<KsefLatarniaFailureEvent>  $ordinaryFailures
     */
    private function evaluatePlannedUnavailability(
        KsefOfflineIssuance $issuance,
        Collection $messages,
        array $ordinaryFailures,
        KsefLatarniaEnvironment $environment,
        KsefLatarniaEvidenceCoverage $coverage,
        ?KsefInvoiceSubmissionStatus $lastSubmissionStatus,
        CarbonImmutable $evaluatedAt,
    ): KsefOfflineSubmissionObligation {
        $integrity = $this->procedureEvidenceIntegrity(
            $issuance,
            $messages,
            $environment,
            KsefLatarniaMessageCategory::Maintenance,
            KsefLatarniaMessageType::MaintenanceAnnouncement,
        );

        if ($integrity !== null) {
            return $this->procedureEvidenceError(
                $issuance,
                $integrity,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
            );
        }

        $projection = $this->maintenance->project($messages, $environment);

        if ($projection->isAmbiguous()) {
            return $this->ambiguousResult(
                $issuance,
                null,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
                $projection->ambiguousEventIds,
                $projection->ambiguousMessageIds,
            );
        }

        $event = collect($projection->events)
            ->first(fn (KsefLatarniaMaintenanceEvent $candidate): bool => $candidate->eventId === $issuance->latarnia_trigger_event_id);

        if (! $event instanceof KsefLatarniaMaintenanceEvent) {
            return $this->procedureEvidenceError(
                $issuance,
                KsefOfflineSubmissionObligationReason::ProcedureEventMissing,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
            );
        }

        if ($event->startAt->greaterThan($issuance->issued_at)
            || $event->endAt->lessThan($issuance->issued_at)) {
            return $this->procedureEvidenceError(
                $issuance,
                KsefOfflineSubmissionObligationReason::ProcedureEventMismatch,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
            );
        }

        if ($event->endAt->greaterThan($evaluatedAt)) {
            if ($ordinaryFailures !== []) {
                return $this->evaluateActiveDeadline(
                    $issuance,
                    null,
                    $ordinaryFailures,
                    KsefOfflineSubmissionObligationReason::PlannedUnavailabilityBase,
                    $coverage,
                    $lastSubmissionStatus,
                    $evaluatedAt,
                    [$event->eventId],
                    [$event->messageId],
                );
            }

            return $this->result(
                $issuance,
                KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd,
                null,
                null,
                KsefOfflineSubmissionObligationReason::PlannedUnavailabilityBase,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
                [$event->eventId],
                [$event->messageId],
            );
        }

        $baseDeadline = $this->calendar->nextBusinessDayAfter(
            $event->endAt->setTimezone(self::LEGAL_TIMEZONE)->startOfDay(),
        );

        return $this->evaluateActiveDeadline(
            $issuance,
            $baseDeadline,
            $ordinaryFailures,
            KsefOfflineSubmissionObligationReason::PlannedUnavailabilityBase,
            $coverage,
            $lastSubmissionStatus,
            $evaluatedAt,
            [$event->eventId],
            [$event->messageId],
        );
    }

    /**
     * @param  Collection<int, KsefLatarniaMessage>  $messages
     * @param  list<KsefLatarniaFailureEvent>  $events
     */
    private function evaluateFailure(
        KsefOfflineIssuance $issuance,
        Collection $messages,
        array $events,
        KsefLatarniaEnvironment $environment,
        KsefLatarniaEvidenceCoverage $coverage,
        ?KsefInvoiceSubmissionStatus $lastSubmissionStatus,
        CarbonImmutable $evaluatedAt,
    ): KsefOfflineSubmissionObligation {
        $integrity = $this->procedureEvidenceIntegrity(
            $issuance,
            $messages,
            $environment,
            KsefLatarniaMessageCategory::Failure,
            KsefLatarniaMessageType::FailureStart,
        );

        if ($integrity !== null) {
            return $this->procedureEvidenceError(
                $issuance,
                $integrity,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
            );
        }

        $ordinaryFailures = array_values(array_filter(
            $events,
            fn (KsefLatarniaFailureEvent $event): bool => $event->category === KsefLatarniaMessageCategory::Failure
                && ! $event->publishedAt->greaterThan($evaluatedAt),
        ));
        $triggerIndex = collect($ordinaryFailures)
            ->search(fn (KsefLatarniaFailureEvent $event): bool => $event->eventId === $issuance->latarnia_trigger_event_id);

        if ($triggerIndex === false) {
            return $this->procedureEvidenceError(
                $issuance,
                KsefOfflineSubmissionObligationReason::ProcedureEventMissing,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
            );
        }

        $trigger = $ordinaryFailures[$triggerIndex];

        if ($trigger->startAt->greaterThan($issuance->issued_at)
            || ($trigger->endAt !== null && $trigger->endAt->lessThan($issuance->issued_at))) {
            return $this->procedureEvidenceError(
                $issuance,
                KsefOfflineSubmissionObligationReason::ProcedureEventMismatch,
                $coverage,
                $lastSubmissionStatus,
                $evaluatedAt,
            );
        }

        return $this->evaluateActiveDeadline(
            $issuance,
            null,
            array_slice($ordinaryFailures, (int) $triggerIndex),
            KsefOfflineSubmissionObligationReason::FailureBase,
            $coverage,
            $lastSubmissionStatus,
            $evaluatedAt,
        );
    }

    /** @param Collection<int, KsefLatarniaMessage> $messages */
    private function procedureEvidenceIntegrity(
        KsefOfflineIssuance $issuance,
        Collection $messages,
        KsefLatarniaEnvironment $environment,
        KsefLatarniaMessageCategory $category,
        KsefLatarniaMessageType $type,
    ): ?KsefOfflineSubmissionObligationReason {
        if ($issuance->latarnia_source_environment !== $environment
            || $issuance->latarnia_trigger_event_id === null
            || ! is_string($issuance->latarnia_trigger_message_id)
            || $issuance->latarnia_trigger_message_id === ''
            || $issuance->latarnia_trigger_message_version === null
            || $issuance->latarnia_trigger_category !== $category
            || $issuance->latarnia_trigger_start_at === null
            || $issuance->latarnia_trigger_published_at === null
            || $issuance->latarnia_evidence_as_of_at === null
            || $issuance->latarnia_evidence_from_at === null
            || $issuance->latarnia_evidence_through_at === null) {
            return KsefOfflineSubmissionObligationReason::ProcedureEventMissing;
        }

        if ($issuance->latarnia_evidence_from_at->greaterThan($issuance->latarnia_evidence_through_at)
            || $issuance->latarnia_evidence_as_of_at->greaterThan($issuance->issued_at)
            || $issuance->latarnia_evidence_through_at->greaterThan($issuance->issued_at)) {
            return KsefOfflineSubmissionObligationReason::ProcedureEventMismatch;
        }

        $message = $messages->first(fn (KsefLatarniaMessage $candidate): bool => $candidate->external_message_id === $issuance->latarnia_trigger_message_id
            && $candidate->version === $issuance->latarnia_trigger_message_version);

        if (! $message instanceof KsefLatarniaMessage) {
            return KsefOfflineSubmissionObligationReason::ProcedureEventMissing;
        }

        if ($message->source_environment !== $environment
            || $message->event_id !== $issuance->latarnia_trigger_event_id
            || $message->category !== $category
            || $message->type !== $type
            || ! $message->start_at->equalTo($issuance->latarnia_trigger_start_at)
            || ! $message->published_at->equalTo($issuance->latarnia_trigger_published_at)
            || ! $this->sameInstant($message->end_at, $issuance->latarnia_trigger_end_at)
            || $message->published_at->greaterThan($issuance->issued_at)
            || $message->first_fetched_at->greaterThan($issuance->issued_at)
            || $message->start_at->greaterThan($issuance->issued_at)
            || ($message->end_at !== null && $message->end_at->lessThan($issuance->issued_at))) {
            return KsefOfflineSubmissionObligationReason::ProcedureEventMismatch;
        }

        return null;
    }

    private function sameInstant(?CarbonImmutable $left, ?CarbonImmutable $right): bool
    {
        return $left === null
            ? $right === null
            : $right !== null && $left->equalTo($right);
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
        KsefOfflineIssuance $issuance,
        ?KsefInvoiceSubmission $submission,
        ?CarbonImmutable $baseDeadline,
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
                return $this->result($issuance, KsefOfflineSubmissionObligationStatus::TransportModeMismatch, $baseDeadline, null, KsefOfflineSubmissionObligationReason::TransportModeMismatch, $coverage, $submission->status, $evaluatedAt);
            }

            if (! $hasReference) {
                return $this->submissionIntegrityResult($issuance, $submission, $baseDeadline, $coverage, $evaluatedAt);
            }

            return $this->result($issuance, KsefOfflineSubmissionObligationStatus::Fulfilled, $baseDeadline, null, KsefOfflineSubmissionObligationReason::SubmissionAlreadyAccepted, $coverage, $submission->status, $evaluatedAt);
        }

        if (in_array($submission->status, [KsefInvoiceSubmissionStatus::Submitted, KsefInvoiceSubmissionStatus::Processing], true)) {
            if (! $hasReference) {
                return $this->submissionIntegrityResult($issuance, $submission, $baseDeadline, $coverage, $evaluatedAt);
            }

            return $this->result($issuance, KsefOfflineSubmissionObligationStatus::SubmittedPendingResult, $baseDeadline, null, KsefOfflineSubmissionObligationReason::SubmissionPendingResult, $coverage, $submission->status, $evaluatedAt);
        }

        if ($submission->status === KsefInvoiceSubmissionStatus::Uncertain) {
            return $this->result($issuance, KsefOfflineSubmissionObligationStatus::TransmissionUncertain, $baseDeadline, null, KsefOfflineSubmissionObligationReason::TransmissionUncertain, $coverage, $submission->status, $evaluatedAt);
        }

        if ($submission->status === KsefInvoiceSubmissionStatus::Rejected) {
            return $this->result($issuance, KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired, $baseDeadline, null, KsefOfflineSubmissionObligationReason::SubmissionRejected, $coverage, $submission->status, $evaluatedAt);
        }

        if ($hasReference) {
            return $this->submissionIntegrityResult($issuance, $submission, $baseDeadline, $coverage, $evaluatedAt);
        }

        return null;
    }

    /**
     * @param  list<KsefLatarniaFailureEvent>  $failures
     * @param  list<int>  $initialEventIds
     * @param  list<string>  $initialMessageIds
     */
    private function evaluateActiveDeadline(
        KsefOfflineIssuance $issuance,
        ?CarbonImmutable $baseDeadline,
        array $failures,
        KsefOfflineSubmissionObligationReason $baseReason,
        KsefLatarniaEvidenceCoverage $coverage,
        ?KsefInvoiceSubmissionStatus $lastSubmissionStatus,
        CarbonImmutable $evaluatedAt,
        array $initialEventIds = [],
        array $initialMessageIds = [],
    ): KsefOfflineSubmissionObligation {
        $effectiveDeadline = $baseDeadline;
        $appliedEvents = $initialEventIds;
        $appliedMessages = $initialMessageIds;
        $lastFailure = null;
        $lastEndDate = null;
        $failureCount = 0;

        foreach ($failures as $failure) {
            if ($lastFailure !== null) {
                if ($failure->publishedAt->equalTo($lastFailure->publishedAt)
                    || $lastFailure->endAt === null
                    || $failure->startAt->lessThan($lastFailure->endAt)) {
                    return $this->ambiguousResult($issuance, $baseDeadline, $coverage, $lastSubmissionStatus, $evaluatedAt, [...$appliedEvents, $failure->eventId], [...$appliedMessages, ...$failure->messageIds]);
                }

                if ($effectiveDeadline !== null
                    && $failure->publishedAt->setTimezone(self::LEGAL_TIMEZONE)->toDateString() > $effectiveDeadline->toDateString()) {
                    break;
                }
            }

            $failureCount++;
            $appliedEvents[] = $failure->eventId;
            $appliedMessages = [...$appliedMessages, ...$failure->messageIds];

            if ($failure->endAt === null || $failure->endAt->greaterThan($evaluatedAt)) {
                return $this->result(
                    $issuance,
                    KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd,
                    $baseDeadline,
                    null,
                    $baseReason === KsefOfflineSubmissionObligationReason::FailureBase && $failureCount === 1
                        ? KsefOfflineSubmissionObligationReason::FailureBase
                        : ($failureCount === 1 ? KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension : KsefOfflineSubmissionObligationReason::SubsequentFailureReset),
                    $coverage,
                    $lastSubmissionStatus,
                    $evaluatedAt,
                    $appliedEvents,
                    array_values(array_unique($appliedMessages)),
                );
            }

            $lastEndDate = $failure->endAt->setTimezone(self::LEGAL_TIMEZONE)->startOfDay();
            $effectiveDeadline = $this->calendar->addBusinessDaysAfter($lastEndDate, 7);
            $baseDeadline ??= $effectiveDeadline;
            $lastFailure = $failure;
        }

        if ($effectiveDeadline === null) {
            return $this->procedureEvidenceError($issuance, KsefOfflineSubmissionObligationReason::ProcedureEventMissing, $coverage, $lastSubmissionStatus, $evaluatedAt);
        }

        $reason = $failureCount === 0
            ? $baseReason
            : ($baseReason === KsefOfflineSubmissionObligationReason::FailureBase && $failureCount === 1
                ? KsefOfflineSubmissionObligationReason::FailureBase
                : ($failureCount === 1 ? KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension : KsefOfflineSubmissionObligationReason::SubsequentFailureReset));
        $localEvaluationDate = $evaluatedAt->setTimezone(self::LEGAL_TIMEZONE)->toDateString();
        $status = match (true) {
            $localEvaluationDate < $effectiveDeadline->toDateString() => KsefOfflineSubmissionObligationStatus::Pending,
            $localEvaluationDate === $effectiveDeadline->toDateString() => KsefOfflineSubmissionObligationStatus::DueToday,
            default => KsefOfflineSubmissionObligationStatus::Overdue,
        };

        return $this->result($issuance, $status, $baseDeadline, $effectiveDeadline, $reason, $coverage, $lastSubmissionStatus, $evaluatedAt, $appliedEvents, array_values(array_unique($appliedMessages)), $lastEndDate);
    }

    private function submissionIntegrityResult(KsefOfflineIssuance $issuance, KsefInvoiceSubmission $submission, ?CarbonImmutable $baseDeadline, KsefLatarniaEvidenceCoverage $coverage, CarbonImmutable $evaluatedAt): KsefOfflineSubmissionObligation
    {
        return $this->result($issuance, KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError, $baseDeadline, null, KsefOfflineSubmissionObligationReason::SubmissionIntegrityFailure, $coverage, $submission->status, $evaluatedAt);
    }

    /** @param list<int> $eventIds @param list<string> $messageIds */
    private function ambiguousResult(KsefOfflineIssuance $issuance, ?CarbonImmutable $baseDeadline, KsefLatarniaEvidenceCoverage $coverage, ?KsefInvoiceSubmissionStatus $lastSubmissionStatus, CarbonImmutable $evaluatedAt, array $eventIds, array $messageIds): KsefOfflineSubmissionObligation
    {
        return $this->result($issuance, KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory, $baseDeadline, null, KsefOfflineSubmissionObligationReason::AmbiguousLatarniaHistory, $coverage, $lastSubmissionStatus, $evaluatedAt, $eventIds, $messageIds);
    }

    private function procedureEvidenceError(KsefOfflineIssuance $issuance, KsefOfflineSubmissionObligationReason $reason, KsefLatarniaEvidenceCoverage $coverage, ?KsefInvoiceSubmissionStatus $lastSubmissionStatus, CarbonImmutable $evaluatedAt): KsefOfflineSubmissionObligation
    {
        return $this->result($issuance, KsefOfflineSubmissionObligationStatus::EvidenceUnavailable, null, null, $reason, $coverage, $lastSubmissionStatus, $evaluatedAt);
    }

    private function latarniaEnvironment(KsefEnvironment $environment): ?KsefLatarniaEnvironment
    {
        return match ($environment) {
            KsefEnvironment::Test => KsefLatarniaEnvironment::Test,
            KsefEnvironment::Production => KsefLatarniaEnvironment::Production,
            KsefEnvironment::Demo => null,
        };
    }

    /** @param list<int> $appliedEventIds @param list<string> $appliedMessageIds */
    private function result(
        KsefOfflineIssuance $issuance,
        KsefOfflineSubmissionObligationStatus $status,
        ?CarbonImmutable $baseDeadline,
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
            $issuance->procedure,
        );
    }
}
