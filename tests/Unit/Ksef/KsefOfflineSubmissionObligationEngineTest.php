<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationReason;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefOfflineSubmissionObligationEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_base_deadline_uses_frozen_p1_and_explicit_as_of(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-09-04',
            'issued_at' => '2026-09-05T08:00:00Z',
        ]);

        $pending = $this->evaluate($issuance, asOf: '2026-09-06T12:00:00Z');
        $due = $this->evaluate($issuance, asOf: '2026-09-07T12:00:00Z');
        $overdue = $this->evaluate($issuance, asOf: '2026-09-08T00:00:00Z');

        $this->assertSame('2026-09-07', $pending->baseDeadline->toDateString());
        $this->assertSame('2026-09-07', $pending->effectiveDeadline?->toDateString());
        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $pending->status);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::DueToday, $due->status);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::Overdue, $overdue->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::Offline24Base, $overdue->reason);
        Http::assertNothingSent();
    }

    public function test_open_failure_published_after_issuance_waits_for_its_end(): void
    {
        $issuance = $this->issuance();
        $failure = $this->failure([
            'published_at' => '2026-09-04T09:00:00Z',
            'start_at' => '2026-09-04T08:30:00Z',
        ]);

        $result = $this->evaluate($issuance, [$failure], asOf: '2026-09-05T12:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, $result->status);
        $this->assertNull($result->effectiveDeadline);
        $this->assertSame([101], $result->appliedEventIds);
        $this->assertSame(['FAILURE-101'], $result->appliedMessageIds);
    }

    public function test_completed_failure_uses_warsaw_end_date_and_seven_business_days(): void
    {
        $result = $this->evaluate(
            $this->issuance(),
            [$this->failure([
                'published_at' => '2026-09-04T09:00:00Z',
                'start_at' => '2026-09-04T08:30:00Z',
                'end_at' => '2026-09-06T10:00:00Z',
            ])],
            asOf: '2026-09-10T10:00:00Z',
        );

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame('2026-09-15', $result->effectiveDeadline?->toDateString());
        $this->assertSame('2026-09-06', $result->ordinaryFailureEndDate?->toDateString());
        $this->assertSame(KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension, $result->reason);
    }

    public function test_failure_published_after_expired_base_deadline_still_applies_before_transmission(): void
    {
        $result = $this->evaluate(
            $this->issuance(),
            [$this->failure([
                'published_at' => '2026-09-08T09:00:00Z',
                'start_at' => '2026-09-08T08:30:00Z',
                'end_at' => '2026-09-09T10:00:00Z',
            ])],
            asOf: '2026-09-10T10:00:00Z',
        );

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame('2026-09-18', $result->effectiveDeadline?->toDateString());
        $this->assertSame(KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension, $result->reason);
    }

    public function test_publication_not_failure_start_controls_after_issuance_trigger(): void
    {
        $issuance = $this->issuance();
        $publishedAfter = $this->failure([
            'start_at' => '2026-09-04T07:00:00Z',
            'published_at' => '2026-09-04T09:00:00Z',
        ]);
        $publishedBefore = $this->failure([
            'event_id' => 102,
            'external_message_id' => 'FAILURE-102',
            'start_at' => '2026-09-04T09:00:00Z',
            'published_at' => '2026-09-04T07:59:59Z',
        ]);

        $triggered = $this->evaluate($issuance, [$publishedAfter], asOf: '2026-09-05T10:00:00Z');
        $notTriggered = $this->evaluate($issuance, [$publishedBefore], asOf: '2026-09-05T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, $triggered->status);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $notTriggered->status);
        $this->assertSame([], $notTriggered->appliedEventIds);
    }

    public function test_three_failure_chain_resets_the_deadline_iteratively(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01T08:00:00Z',
        ]);
        $failures = [
            $this->failure([
                'event_id' => 1,
                'external_message_id' => 'F-1',
                'start_at' => '2026-09-01T09:00:00Z',
                'published_at' => '2026-09-01T09:01:00Z',
                'end_at' => '2026-09-02T10:00:00Z',
            ]),
            $this->failure([
                'event_id' => 2,
                'external_message_id' => 'F-2',
                'start_at' => '2026-09-11T19:00:00Z',
                'published_at' => '2026-09-11T21:59:00Z',
                'end_at' => '2026-09-14T10:00:00Z',
            ]),
            $this->failure([
                'event_id' => 3,
                'external_message_id' => 'F-3',
                'start_at' => '2026-09-20T09:00:00Z',
                'published_at' => '2026-09-20T09:01:00Z',
                'end_at' => '2026-09-21T10:00:00Z',
            ]),
        ];

        $result = $this->evaluate($issuance, $failures, asOf: '2026-09-22T12:00:00Z');

        $this->assertSame('2026-09-30', $result->effectiveDeadline?->toDateString());
        $this->assertSame([1, 2, 3], $result->appliedEventIds);
        $this->assertSame(KsefOfflineSubmissionObligationReason::SubsequentFailureReset, $result->reason);
        $this->assertSame('2026-09-21', $result->ordinaryFailureEndDate?->toDateString());
    }

    public function test_subsequent_failure_after_deadline_does_not_reset_it(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01T08:00:00Z',
        ]);
        $result = $this->evaluate($issuance, [
            $this->failure([
                'event_id' => 1,
                'external_message_id' => 'F-1',
                'start_at' => '2026-09-01T09:00:00Z',
                'published_at' => '2026-09-01T09:01:00Z',
                'end_at' => '2026-09-02T10:00:00Z',
            ]),
            $this->failure([
                'event_id' => 2,
                'external_message_id' => 'F-2',
                'start_at' => '2026-09-12T08:00:00Z',
                'published_at' => '2026-09-12T08:01:00Z',
                'end_at' => '2026-09-14T10:00:00Z',
            ]),
        ], asOf: '2026-09-12T12:00:00Z');

        $this->assertSame('2026-09-11', $result->effectiveDeadline?->toDateString());
        $this->assertSame([1], $result->appliedEventIds);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::Overdue, $result->status);
    }

    public function test_subsequent_open_failure_published_within_current_deadline_waits_for_its_end(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01T08:00:00Z',
        ]);
        $result = $this->evaluate($issuance, [
            $this->failure([
                'event_id' => 1,
                'external_message_id' => 'F-1',
                'start_at' => '2026-09-01T09:00:00Z',
                'published_at' => '2026-09-01T09:01:00Z',
                'end_at' => '2026-09-02T10:00:00Z',
            ]),
            $this->failure([
                'event_id' => 2,
                'external_message_id' => 'F-2',
                'start_at' => '2026-09-10T08:00:00Z',
                'published_at' => '2026-09-10T08:01:00Z',
                'end_at' => null,
            ]),
        ], asOf: '2026-09-10T12:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::SubsequentFailureReset, $result->reason);
        $this->assertSame([1, 2], $result->appliedEventIds);
        $this->assertNull($result->effectiveDeadline);
    }

    public function test_publication_on_deadline_day_applies_until_warsaw_midnight(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01T08:00:00Z',
        ]);
        $first = $this->failure([
            'event_id' => 1,
            'external_message_id' => 'F-1',
            'start_at' => '2026-09-01T09:00:00Z',
            'published_at' => '2026-09-01T09:01:00Z',
            'end_at' => '2026-09-02T10:00:00Z',
        ]);
        $beforeMidnight = $this->failure([
            'event_id' => 2,
            'external_message_id' => 'F-2',
            'start_at' => '2026-09-11T20:00:00Z',
            'published_at' => '2026-09-11T21:59:59Z',
            'end_at' => '2026-09-14T10:00:00Z',
        ]);
        $afterMidnight = $this->failure([
            'event_id' => 3,
            'external_message_id' => 'F-3',
            'start_at' => '2026-09-11T22:00:00Z',
            'published_at' => '2026-09-11T22:00:00Z',
            'end_at' => '2026-09-14T10:00:00Z',
        ]);

        $inside = $this->evaluate($issuance, [$first, $beforeMidnight], asOf: '2026-09-15T12:00:00Z');
        $outside = $this->evaluate($issuance, [$first, $afterMidnight], asOf: '2026-09-15T12:00:00Z');

        $this->assertSame([1, 2], $inside->appliedEventIds);
        $this->assertSame('2026-09-23', $inside->effectiveDeadline?->toDateString());
        $this->assertSame([1], $outside->appliedEventIds);
        $this->assertSame('2026-09-11', $outside->effectiveDeadline?->toDateString());
    }

    public function test_total_failure_after_issuance_has_priority_without_business_mutation(): void
    {
        $issuance = $this->issuance();
        $attributesBefore = $issuance->getAttributes();
        $ordinary = $this->failure([
            'published_at' => '2026-09-04T09:00:00Z',
            'start_at' => '2026-09-04T08:30:00Z',
        ]);
        $total = $this->failure([
            'event_id' => 202,
            'external_message_id' => 'TOTAL-202',
            'category' => 'TOTAL_FAILURE',
            'published_at' => '2026-09-05T09:00:00Z',
            'start_at' => '2026-09-05T08:30:00Z',
        ]);

        $result = $this->evaluate($issuance, [$ordinary, $total], asOf: '2026-09-06T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::TotalFailureNoSubmission, $result->reason);
        $this->assertSame(202, $result->totalFailureEventId);
        $this->assertSame([202], $result->appliedEventIds);
        $this->assertSame($attributesBefore, $issuance->getAttributes());
    }

    public function test_total_failure_before_issuance_and_maintenance_do_not_change_offline24_deadline(): void
    {
        $totalBefore = $this->failure([
            'event_id' => 201,
            'external_message_id' => 'TOTAL-201',
            'category' => 'TOTAL_FAILURE',
            'published_at' => '2026-09-04T07:59:59Z',
            'start_at' => '2026-09-04T07:00:00Z',
        ]);
        $maintenance = $this->failure([
            'event_id' => 301,
            'external_message_id' => 'MAINTENANCE-301',
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'published_at' => '2026-09-04T09:00:00Z',
            'start_at' => '2026-09-05T08:00:00Z',
            'end_at' => '2026-09-05T10:00:00Z',
        ]);

        $result = $this->evaluate(
            $this->issuance(),
            [$totalBefore, $maintenance],
            asOf: '2026-09-05T10:00:00Z',
        );

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::Offline24Base, $result->reason);
        $this->assertSame([], $result->appliedEventIds);
    }

    public function test_total_failure_during_post_failure_deadline_removes_submission_obligation(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01T08:00:00Z',
        ]);
        $ordinary = $this->failure([
            'event_id' => 1,
            'external_message_id' => 'F-1',
            'start_at' => '2026-09-01T09:00:00Z',
            'published_at' => '2026-09-01T09:01:00Z',
            'end_at' => '2026-09-02T10:00:00Z',
        ]);
        $total = $this->failure([
            'event_id' => 2,
            'external_message_id' => 'TOTAL-2',
            'category' => 'TOTAL_FAILURE',
            'start_at' => '2026-09-10T08:00:00Z',
            'published_at' => '2026-09-10T08:01:00Z',
        ]);

        $result = $this->evaluate($issuance, [$ordinary, $total], asOf: '2026-09-10T12:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure, $result->status);
        $this->assertSame(2, $result->totalFailureEventId);
        $this->assertSame([2], $result->appliedEventIds);
    }

    public function test_evidence_coverage_and_environment_isolation_are_explicit(): void
    {
        $productionMessage = $this->failure([
            'source_environment' => KsefLatarniaEnvironment::Production,
            'published_at' => '2026-09-04T09:00:00Z',
        ]);
        $complete = $this->evaluate($this->issuance(), [$productionMessage], asOf: '2026-09-05T10:00:00Z');
        $insufficient = $this->evaluate(
            $this->issuance(),
            coverage: KsefLatarniaEvidenceCoverage::Insufficient,
            asOf: '2026-09-05T10:00:00Z',
        );
        $demo = $this->evaluate(
            $this->issuance(['environment' => KsefEnvironment::Demo]),
            [$this->failure()],
            coverage: KsefLatarniaEvidenceCoverage::Complete,
            asOf: '2026-09-05T10:00:00Z',
        );

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $complete->status);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::EvidenceUnavailable, $insufficient->status);
        $this->assertNull($insufficient->effectiveDeadline);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::EvidenceUnavailable, $demo->status);
        $this->assertSame(KsefLatarniaEvidenceCoverage::UnsupportedEnvironment, $demo->evidenceCoverage);
        $this->assertNull($demo->effectiveDeadline);
    }

    public function test_production_issuance_uses_only_production_latarnia_messages(): void
    {
        $issuance = $this->issuance(['environment' => KsefEnvironment::Production]);
        $testMessage = $this->failure([
            'event_id' => 1,
            'external_message_id' => 'TEST-1',
            'source_environment' => KsefLatarniaEnvironment::Test,
        ]);
        $productionMessage = $this->failure([
            'event_id' => 2,
            'external_message_id' => 'PRODUCTION-2',
            'source_environment' => KsefLatarniaEnvironment::Production,
        ]);

        $result = $this->evaluate(
            $issuance,
            [$testMessage, $productionMessage],
            asOf: '2026-09-05T10:00:00Z',
        );

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, $result->status);
        $this->assertSame([2], $result->appliedEventIds);
        $this->assertSame(['PRODUCTION-2'], $result->appliedMessageIds);
    }

    #[DataProvider('submissionCases')]
    public function test_latest_linked_submission_state_has_explicit_obligation_semantics(
        KsefInvoiceSubmissionStatus $submissionStatus,
        ?KsefInvoicingMode $mode,
        ?string $reference,
        KsefOfflineSubmissionObligationStatus $expectedStatus,
    ): void {
        $issuance = $this->issuance();
        $submission = $this->submission($issuance, [
            'status' => $submissionStatus,
            'invoicing_mode' => $mode,
            'invoice_reference_number' => $reference,
        ]);

        $result = $this->evaluate(
            $issuance,
            submissions: [$submission],
            coverage: KsefLatarniaEvidenceCoverage::Complete,
            asOf: '2026-09-05T10:00:00Z',
        );

        $this->assertSame($expectedStatus, $result->status);
        $this->assertSame($submissionStatus, $result->lastSubmissionStatus);
    }

    public static function submissionCases(): array
    {
        return [
            'preparing remains active' => [KsefInvoiceSubmissionStatus::Preparing, null, null, KsefOfflineSubmissionObligationStatus::Pending],
            'session opened remains active' => [KsefInvoiceSubmissionStatus::SessionOpened, null, null, KsefOfflineSubmissionObligationStatus::Pending],
            'technical failure remains active' => [KsefInvoiceSubmissionStatus::TechnicalFailed, null, null, KsefOfflineSubmissionObligationStatus::Pending],
            'submitted is pending result' => [KsefInvoiceSubmissionStatus::Submitted, null, 'REF-1', KsefOfflineSubmissionObligationStatus::SubmittedPendingResult],
            'processing is pending result' => [KsefInvoiceSubmissionStatus::Processing, null, 'REF-1', KsefOfflineSubmissionObligationStatus::SubmittedPendingResult],
            'accepted Offline is fulfilled' => [KsefInvoiceSubmissionStatus::Accepted, KsefInvoicingMode::Offline, 'REF-1', KsefOfflineSubmissionObligationStatus::Fulfilled],
            'accepted Online is mode mismatch' => [KsefInvoiceSubmissionStatus::Accepted, KsefInvoicingMode::Online, 'REF-1', KsefOfflineSubmissionObligationStatus::TransportModeMismatch],
            'rejected needs remediation' => [KsefInvoiceSubmissionStatus::Rejected, null, 'REF-1', KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired],
            'uncertain stays uncertain' => [KsefInvoiceSubmissionStatus::Uncertain, null, 'REF-1', KsefOfflineSubmissionObligationStatus::TransmissionUncertain],
        ];
    }

    public function test_accepted_offline_is_fulfilled_even_without_latarnia_coverage(): void
    {
        $issuance = $this->issuance();
        $result = $this->evaluate(
            $issuance,
            submissions: [$this->submission($issuance, [
                'status' => KsefInvoiceSubmissionStatus::Accepted,
                'invoicing_mode' => KsefInvoicingMode::Offline,
                'invoice_reference_number' => 'REF-ACCEPTED',
            ])],
            coverage: KsefLatarniaEvidenceCoverage::Insufficient,
        );

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Fulfilled, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::SubmissionAlreadyAccepted, $result->reason);
    }

    public function test_attempt_number_not_updated_at_selects_the_latest_submission(): void
    {
        $issuance = $this->issuance();
        $olderAttempt = $this->submission($issuance, [
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::TechnicalFailed,
            'updated_at' => '2026-09-30T10:00:00Z',
        ]);
        $latestAttempt = $this->submission($issuance, [
            'attempt_number' => 2,
            'status' => KsefInvoiceSubmissionStatus::Submitted,
            'invoice_reference_number' => 'REF-2',
            'updated_at' => '2026-09-01T10:00:00Z',
        ]);

        $result = $this->evaluate($issuance, submissions: [$olderAttempt, $latestAttempt]);

        $this->assertSame(KsefOfflineSubmissionObligationStatus::SubmittedPendingResult, $result->status);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $result->lastSubmissionStatus);
    }

    public function test_inconsistent_submission_data_fails_closed(): void
    {
        $issuance = $this->issuance();
        $missingReference = $this->submission($issuance, [
            'status' => KsefInvoiceSubmissionStatus::Submitted,
            'invoice_reference_number' => null,
        ]);
        $wrongInvoice = $this->submission($issuance, ['invoice_id' => 999]);

        $first = $this->evaluate($issuance, submissions: [$missingReference]);
        $second = $this->evaluate($issuance, submissions: [$wrongInvoice]);

        $this->assertSame(KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError, $first->status);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError, $second->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::SubmissionIntegrityFailure, $second->reason);
    }

    public function test_duplicate_attempt_numbers_fail_closed(): void
    {
        $issuance = $this->issuance();
        $first = $this->submission($issuance, ['id' => 1, 'attempt_number' => 2]);
        $second = $this->submission($issuance, ['id' => 2, 'attempt_number' => 2]);

        $result = $this->evaluate($issuance, submissions: [$first, $second]);

        $this->assertSame(KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::SubmissionIntegrityFailure, $result->reason);
    }

    public function test_ambiguous_or_overlapping_failure_history_fails_closed(): void
    {
        $duplicateStart = $this->failure();
        $sameEventStart = $this->failure(['external_message_id' => 'SECOND-START']);
        $projectionConflict = $this->evaluate(
            $this->issuance(),
            [$duplicateStart, $sameEventStart],
            asOf: '2026-09-05T10:00:00Z',
        );
        $overlap = $this->evaluate($this->issuance(), [
            $this->failure([
                'event_id' => 1,
                'external_message_id' => 'F-1',
                'published_at' => '2026-09-04T09:00:00Z',
                'start_at' => '2026-09-04T08:30:00Z',
                'end_at' => '2026-09-06T10:00:00Z',
            ]),
            $this->failure([
                'event_id' => 2,
                'external_message_id' => 'F-2',
                'published_at' => '2026-09-05T09:00:00Z',
                'start_at' => '2026-09-05T08:30:00Z',
                'end_at' => '2026-09-07T10:00:00Z',
            ]),
        ], asOf: '2026-09-08T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory, $projectionConflict->status);
        $this->assertSame(KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory, $overlap->status);
        $this->assertNull($overlap->effectiveDeadline);
    }

    public function test_warsaw_date_is_used_at_cest_midnight_and_dst_boundaries(): void
    {
        $issuance = $this->issuance([
            'issue_date' => '2026-10-20',
            'issued_at' => '2026-10-20T08:00:00Z',
        ]);
        $fall = $this->evaluate($issuance, [$this->failure([
            'published_at' => '2026-10-20T09:00:00Z',
            'start_at' => '2026-10-20T08:30:00Z',
            'end_at' => '2026-10-23T22:30:00Z',
        ])], asOf: '2026-10-26T12:00:00Z');
        $spring = $this->evaluate($this->issuance([
            'issue_date' => '2026-03-27',
            'issued_at' => '2026-03-27T08:00:00Z',
        ]), [$this->failure([
            'published_at' => '2026-03-27T09:00:00Z',
            'start_at' => '2026-03-27T08:30:00Z',
            'end_at' => '2026-03-28T23:30:00Z',
        ])], asOf: '2026-03-30T12:00:00Z');

        $this->assertSame('2026-10-24', $fall->ordinaryFailureEndDate?->toDateString());
        $this->assertSame('2026-11-03', $fall->effectiveDeadline?->toDateString());
        $this->assertSame('2026-03-29', $spring->ordinaryFailureEndDate?->toDateString());
        $this->assertSame('2026-04-08', $spring->effectiveDeadline?->toDateString());
    }

    public function test_planned_unavailability_waits_for_the_current_end_of_the_frozen_maintenance_event(): void
    {
        $message = $this->maintenance([
            'end_at' => '2026-09-04T14:00:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $message);

        $result = $this->evaluate($issuance, [$message], asOf: '2026-09-04T12:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::PlannedUnavailabilityBase, $result->reason);
        $this->assertNull($result->baseDeadline);
        $this->assertNull($result->effectiveDeadline);
        $this->assertSame(KsefOfflineIssuanceProcedure::PlannedUnavailability, $result->procedure);
    }

    public function test_open_failure_during_active_planned_unavailability_takes_priority_immediately(): void
    {
        $maintenance = $this->maintenance([
            'start_at' => '2026-09-04T08:00:00Z',
            'end_at' => '2026-09-04T14:00:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $maintenance);
        $issuance->forceFill(['issued_at' => CarbonImmutable::parse('2026-09-04T09:00:00Z')]);
        $failure = $this->failure([
            'event_id' => 401,
            'external_message_id' => 'FAILURE-401',
            'start_at' => '2026-09-04T10:00:00Z',
            'published_at' => '2026-09-04T10:01:00Z',
            'first_fetched_at' => '2026-09-04T10:02:00Z',
            'end_at' => null,
        ]);

        $result = $this->evaluate($issuance, [$maintenance, $failure], asOf: '2026-09-04T11:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension, $result->reason);
        $this->assertNull($result->baseDeadline);
        $this->assertNull($result->effectiveDeadline);
        $this->assertSame([301, 401], $result->appliedEventIds);
        $this->assertContains('MAINTENANCE-301', $result->appliedMessageIds);
        $this->assertContains('FAILURE-401', $result->appliedMessageIds);
    }

    public function test_closed_failure_keeps_priority_while_planned_unavailability_is_still_active(): void
    {
        $maintenance = $this->maintenance([
            'start_at' => '2026-09-04T08:00:00Z',
            'end_at' => '2026-09-04T18:00:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $maintenance);
        $issuance->forceFill(['issued_at' => CarbonImmutable::parse('2026-09-04T09:00:00Z')]);
        $failure = $this->failure([
            'event_id' => 402,
            'external_message_id' => 'FAILURE-402',
            'start_at' => '2026-09-04T10:00:00Z',
            'published_at' => '2026-09-04T10:01:00Z',
            'first_fetched_at' => '2026-09-04T10:02:00Z',
            'end_at' => '2026-09-04T12:00:00Z',
        ]);

        $result = $this->evaluate($issuance, [$maintenance, $failure], asOf: '2026-09-04T13:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension, $result->reason);
        $this->assertSame('2026-09-15', $result->baseDeadline?->toDateString());
        $this->assertSame('2026-09-15', $result->effectiveDeadline?->toDateString());
        $this->assertSame([301, 402], $result->appliedEventIds);
    }

    public function test_failure_after_completed_maintenance_keeps_existing_extension_semantics(): void
    {
        $maintenance = $this->maintenance([
            'start_at' => '2026-09-04T08:00:00Z',
            'end_at' => '2026-09-04T09:30:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $maintenance);
        $issuance->forceFill(['issued_at' => CarbonImmutable::parse('2026-09-04T09:00:00Z')]);
        $failure = $this->failure([
            'event_id' => 403,
            'external_message_id' => 'FAILURE-403',
            'start_at' => '2026-09-04T10:00:00Z',
            'published_at' => '2026-09-04T10:01:00Z',
            'first_fetched_at' => '2026-09-04T10:02:00Z',
            'end_at' => '2026-09-04T12:00:00Z',
        ]);

        $result = $this->evaluate($issuance, [$maintenance, $failure], asOf: '2026-09-04T13:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension, $result->reason);
        $this->assertSame('2026-09-07', $result->baseDeadline?->toDateString());
        $this->assertSame('2026-09-15', $result->effectiveDeadline?->toDateString());
    }

    public function test_total_failure_keeps_priority_over_failure_during_planned_unavailability(): void
    {
        $maintenance = $this->maintenance([
            'start_at' => '2026-09-04T08:00:00Z',
            'end_at' => '2026-09-04T14:00:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $maintenance);
        $issuance->forceFill(['issued_at' => CarbonImmutable::parse('2026-09-04T09:00:00Z')]);
        $attributesBefore = $issuance->getAttributes();
        $ordinary = $this->failure([
            'event_id' => 404,
            'external_message_id' => 'FAILURE-404',
            'start_at' => '2026-09-04T10:00:00Z',
            'published_at' => '2026-09-04T10:01:00Z',
        ]);
        $total = $this->failure([
            'event_id' => 405,
            'external_message_id' => 'TOTAL-405',
            'category' => 'TOTAL_FAILURE',
            'start_at' => '2026-09-04T10:30:00Z',
            'published_at' => '2026-09-04T10:31:00Z',
        ]);

        $result = $this->evaluate($issuance, [$maintenance, $ordinary, $total], asOf: '2026-09-04T11:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::TotalFailureNoSubmission, $result->reason);
        $this->assertSame(405, $result->totalFailureEventId);
        $this->assertSame($attributesBefore, $issuance->getAttributes());
    }

    public function test_planned_unavailability_uses_updated_end_of_the_same_event_for_deadline(): void
    {
        $frozen = $this->maintenance([
            'version' => 1,
            'end_at' => '2026-09-04T10:00:00Z',
        ]);
        $updated = $this->maintenance([
            'version' => 2,
            'end_at' => '2026-09-05T10:00:00Z',
            'published_at' => '2026-09-04T09:30:00Z',
            'first_fetched_at' => '2026-09-04T09:31:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $frozen);

        $result = $this->evaluate($issuance, [$frozen, $updated], asOf: '2026-09-06T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame('2026-09-07', $result->baseDeadline?->toDateString());
        $this->assertSame('2026-09-07', $result->effectiveDeadline?->toDateString());
    }

    public function test_failure_has_no_artificial_deadline_until_trigger_event_ends(): void
    {
        $message = $this->failure([
            'published_at' => '2026-09-04T07:50:00Z',
            'first_fetched_at' => '2026-09-04T07:55:00Z',
            'start_at' => '2026-09-04T07:30:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::Failure, $message);

        $result = $this->evaluate($issuance, [$message], asOf: '2026-09-05T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::FailureBase, $result->reason);
        $this->assertNull($result->baseDeadline);
        $this->assertNull($result->effectiveDeadline);
    }

    public function test_failure_deadline_is_seven_business_days_after_its_end(): void
    {
        $message = $this->failure([
            'published_at' => '2026-09-04T07:50:00Z',
            'first_fetched_at' => '2026-09-04T07:55:00Z',
            'start_at' => '2026-09-04T07:30:00Z',
            'end_at' => '2026-09-04T10:00:00Z',
        ]);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::Failure, $message);

        $result = $this->evaluate($issuance, [$message], asOf: '2026-09-05T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Pending, $result->status);
        $this->assertSame('2026-09-15', $result->baseDeadline?->toDateString());
        $this->assertSame('2026-09-15', $result->effectiveDeadline?->toDateString());
    }

    public function test_failure_during_planned_unavailability_replaces_maintenance_deadline(): void
    {
        $maintenance = $this->maintenance(['end_at' => '2026-09-04T10:00:00Z']);
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $maintenance);
        $failure = $this->failure([
            'event_id' => 202,
            'external_message_id' => 'FAILURE-202',
            'start_at' => '2026-09-04T09:00:00Z',
            'published_at' => '2026-09-04T09:05:00Z',
            'first_fetched_at' => '2026-09-04T09:06:00Z',
            'end_at' => '2026-09-05T10:00:00Z',
        ]);

        $result = $this->evaluate($issuance, [$maintenance, $failure], asOf: '2026-09-06T10:00:00Z');

        $this->assertSame(KsefOfflineSubmissionObligationReason::OrdinaryFailureExtension, $result->reason);
        $this->assertSame('2026-09-15', $result->effectiveDeadline?->toDateString());
        $this->assertContains(202, $result->appliedEventIds);
    }

    public function test_procedure_trigger_mismatch_fails_closed(): void
    {
        $message = $this->maintenance();
        $issuance = $this->procedureIssuance(KsefOfflineIssuanceProcedure::PlannedUnavailability, $message);
        $issuance->forceFill(['latarnia_trigger_message_version' => 999]);

        $result = $this->evaluate($issuance, [$message]);

        $this->assertSame(KsefOfflineSubmissionObligationStatus::EvidenceUnavailable, $result->status);
        $this->assertSame(KsefOfflineSubmissionObligationReason::ProcedureEventMissing, $result->reason);
        $this->assertNull($result->effectiveDeadline);
    }

    public function test_submission_state_precedes_missing_procedure_evidence(): void
    {
        $issuance = $this->issuance(['procedure' => KsefOfflineIssuanceProcedure::Failure]);
        $submission = $this->submission($issuance, [
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'invoicing_mode' => KsefInvoicingMode::Offline,
            'invoice_reference_number' => 'REF-ACCEPTED',
        ]);

        $result = $this->evaluate($issuance, submissions: [$submission]);

        $this->assertSame(KsefOfflineSubmissionObligationStatus::Fulfilled, $result->status);
        $this->assertSame(KsefOfflineIssuanceProcedure::Failure, $result->procedure);
    }

    private function evaluate(
        KsefOfflineIssuance $issuance,
        array $messages = [],
        array $submissions = [],
        KsefLatarniaEvidenceCoverage $coverage = KsefLatarniaEvidenceCoverage::Complete,
        string $asOf = '2026-09-05T10:00:00Z',
    ) {
        return app(KsefOfflineSubmissionObligationEngine::class)->evaluate(
            $issuance,
            $messages,
            $submissions,
            CarbonImmutable::parse($asOf),
            $coverage,
        );
    }

    private function issuance(array $overrides = []): KsefOfflineIssuance
    {
        $attributes = array_replace([
            'id' => 50,
            'invoice_id' => 10,
            'environment' => KsefEnvironment::Test,
            'procedure' => 'offline24',
            'issue_date' => '2026-09-04',
            'issued_at' => '2026-09-04T08:00:00Z',
        ], $overrides);

        if (is_string($attributes['issued_at'])) {
            $attributes['issued_at'] = CarbonImmutable::parse($attributes['issued_at'])
                ->setTimezone((string) config('app.timezone'));
        }

        return (new KsefOfflineIssuance)->forceFill($attributes);
    }

    private function procedureIssuance(
        KsefOfflineIssuanceProcedure $procedure,
        KsefLatarniaMessage $message,
    ): KsefOfflineIssuance {
        return $this->issuance([
            'procedure' => $procedure,
            'latarnia_source_environment' => $message->source_environment,
            'latarnia_trigger_event_id' => $message->event_id,
            'latarnia_trigger_message_id' => $message->external_message_id,
            'latarnia_trigger_message_version' => $message->version,
            'latarnia_trigger_category' => $message->category,
            'latarnia_trigger_start_at' => $message->start_at,
            'latarnia_trigger_end_at' => $message->end_at,
            'latarnia_trigger_published_at' => $message->published_at,
            'latarnia_evidence_as_of_at' => CarbonImmutable::parse('2026-09-04T07:59:00Z'),
            'latarnia_evidence_from_at' => CarbonImmutable::parse('2026-09-01T00:00:00Z'),
            'latarnia_evidence_through_at' => CarbonImmutable::parse('2026-09-04T07:59:00Z'),
        ]);
    }

    private function maintenance(array $overrides = []): KsefLatarniaMessage
    {
        $attributes = array_replace([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'MAINTENANCE-301',
            'event_id' => 301,
            'version' => 1,
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'title' => 'Synthetic maintenance',
            'text' => 'Synthetic maintenance fixture.',
            'start_at' => '2026-09-04T07:00:00Z',
            'end_at' => '2026-09-04T12:00:00Z',
            'published_at' => '2026-09-04T06:00:00Z',
            'payload_json' => '{}',
            'payload_hash' => str_repeat('M', 44),
            'first_fetched_at' => '2026-09-04T06:01:00Z',
            'last_seen_at' => '2026-09-04T06:01:00Z',
        ], $overrides);

        foreach (['start_at', 'end_at', 'published_at', 'first_fetched_at', 'last_seen_at'] as $field) {
            if (is_string($attributes[$field])) {
                $attributes[$field] = CarbonImmutable::parse($attributes[$field]);
            }
        }

        return new KsefLatarniaMessage($attributes);
    }

    private function failure(array $overrides = []): KsefLatarniaMessage
    {
        $attributes = array_replace([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'FAILURE-101',
            'event_id' => 101,
            'version' => 1,
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'title' => 'Synthetic failure',
            'text' => 'Synthetic failure fixture.',
            'start_at' => '2026-09-04T08:30:00Z',
            'end_at' => null,
            'published_at' => '2026-09-04T09:00:00Z',
            'payload_json' => '{}',
            'payload_hash' => str_repeat('A', 44),
            'first_fetched_at' => '2026-09-04T09:01:00Z',
            'last_seen_at' => '2026-09-04T09:01:00Z',
        ], $overrides);

        if (! array_key_exists('first_fetched_at', $overrides)) {
            $attributes['first_fetched_at'] = $attributes['published_at'];
        }

        if (! array_key_exists('last_seen_at', $overrides)) {
            $attributes['last_seen_at'] = $attributes['first_fetched_at'];
        }

        foreach (['start_at', 'end_at', 'published_at', 'first_fetched_at', 'last_seen_at'] as $field) {
            if (is_string($attributes[$field])) {
                $attributes[$field] = CarbonImmutable::parse($attributes[$field]);
            }
        }

        return new KsefLatarniaMessage($attributes);
    }

    private function submission(KsefOfflineIssuance $issuance, array $overrides = []): KsefInvoiceSubmission
    {
        return (new KsefInvoiceSubmission)->forceFill(array_replace([
            'id' => 70,
            'invoice_id' => $issuance->invoice_id,
            'offline_issuance_id' => $issuance->getKey(),
            'environment' => $issuance->environment,
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::TechnicalFailed,
            'invoicing_mode' => null,
            'invoice_reference_number' => null,
        ], $overrides));
    }
}
