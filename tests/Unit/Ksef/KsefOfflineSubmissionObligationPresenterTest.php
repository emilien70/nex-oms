<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationReason;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationPresenter;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefOfflineSubmissionObligationPresenterTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function test_every_obligation_status_has_an_explicit_polish_presentation(
        KsefOfflineSubmissionObligationStatus $status,
        string $expectedLabel,
        string $expectedVariant,
        bool $expectedUrgent,
    ): void {
        $presentation = app(KsefOfflineSubmissionObligationPresenter::class)->present(
            KsefEnvironment::Test,
            $this->obligation($status),
        );

        $this->assertSame($expectedLabel, $presentation->label);
        $this->assertSame($expectedVariant, $presentation->variant);
        $this->assertSame($expectedUrgent, $presentation->urgent);
        $this->assertStringContainsString('Stan wg danych Latarni na:', $presentation->tooltip);
    }

    public function test_demo_unsupported_evidence_is_informative_but_not_a_global_urgent_warning(): void
    {
        $presentation = app(KsefOfflineSubmissionObligationPresenter::class)->present(
            KsefEnvironment::Demo,
            $this->obligation(
                KsefOfflineSubmissionObligationStatus::EvidenceUnavailable,
                KsefLatarniaEvidenceCoverage::UnsupportedEnvironment,
            ),
        );

        $this->assertSame('Offline24 · brak pełnych danych Latarni', $presentation->label);
        $this->assertFalse($presentation->urgent);
        $this->assertStringContainsString('Pełny termin wymaga aktualnych danych Latarni.', $presentation->tooltip);
    }

    public function test_procedure_prefix_and_unknown_failure_deadline_are_explicit(): void
    {
        $obligation = new KsefOfflineSubmissionObligation(
            status: KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd,
            baseDeadline: null,
            effectiveDeadline: null,
            reason: KsefOfflineSubmissionObligationReason::FailureBase,
            evidenceCoverage: KsefLatarniaEvidenceCoverage::Complete,
            appliedEventIds: [9],
            appliedMessageIds: ['FAILURE-9'],
            lastSubmissionStatus: null,
            evaluatedAt: CarbonImmutable::parse('2026-09-04T10:00:00Z'),
            procedure: KsefOfflineIssuanceProcedure::Failure,
        );

        $presentation = app(KsefOfflineSubmissionObligationPresenter::class)->present(
            KsefEnvironment::Test,
            $obligation,
        );

        $this->assertSame('Tryb awaryjny · trwa awaria KSeF', $presentation->label);
        $this->assertStringContainsString('Termin zostanie wyznaczony po zakończeniu awarii KSeF.', $presentation->tooltip);
        $this->assertStringNotContainsString('Bazowy termin', $presentation->tooltip);
    }

    public function test_planned_unavailability_has_its_own_prefix(): void
    {
        $obligation = $this->obligation(KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd);
        $obligation = new KsefOfflineSubmissionObligation(
            status: $obligation->status,
            baseDeadline: null,
            effectiveDeadline: null,
            reason: KsefOfflineSubmissionObligationReason::PlannedUnavailabilityBase,
            evidenceCoverage: $obligation->evidenceCoverage,
            appliedEventIds: [],
            appliedMessageIds: [],
            lastSubmissionStatus: null,
            evaluatedAt: $obligation->evaluatedAt,
            procedure: KsefOfflineIssuanceProcedure::PlannedUnavailability,
        );

        $presentation = app(KsefOfflineSubmissionObligationPresenter::class)->present(
            KsefEnvironment::Test,
            $obligation,
        );

        $this->assertSame('Offline – niedostępność · trwa przerwa KSeF', $presentation->label);
    }

    public static function statusProvider(): array
    {
        return [
            'pending' => [KsefOfflineSubmissionObligationStatus::Pending, 'Offline24 · do 07.09.2026', 'info', false],
            'due today' => [KsefOfflineSubmissionObligationStatus::DueToday, 'Offline24 · termin dzisiaj', 'warning', true],
            'overdue' => [KsefOfflineSubmissionObligationStatus::Overdue, 'Offline24 · po terminie', 'danger', true],
            'failure ongoing' => [KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd, 'Offline24 · trwa awaria KSeF', 'warning', false],
            'maintenance ongoing' => [KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd, 'Offline24 · trwa przerwa KSeF', 'warning', false],
            'total failure' => [KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure, 'Offline24 · brak obowiązku wysyłki wg projekcji Latarni', 'info', false],
            'submission pending' => [KsefOfflineSubmissionObligationStatus::SubmittedPendingResult, 'Offline24 · wysłano, oczekiwanie na wynik', 'info', false],
            'fulfilled' => [KsefOfflineSubmissionObligationStatus::Fulfilled, 'Offline24 · obowiązek wykonany', 'success', false],
            'uncertain' => [KsefOfflineSubmissionObligationStatus::TransmissionUncertain, 'Offline24 · wynik wysyłki niepewny', 'danger', true],
            'rejected' => [KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired, 'Offline24 · dokument odrzucony', 'danger', true],
            'mode mismatch' => [KsefOfflineSubmissionObligationStatus::TransportModeMismatch, 'Offline24 · niezgodny tryb KSeF', 'danger', true],
            'evidence unavailable' => [KsefOfflineSubmissionObligationStatus::EvidenceUnavailable, 'Offline24 · brak pełnych danych Latarni', 'warning', true],
            'ambiguous history' => [KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory, 'Offline24 · niejednoznaczna historia Latarni', 'danger', true],
            'integrity error' => [KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError, 'Offline24 · błąd integralności danych', 'danger', true],
        ];
    }

    private function obligation(
        KsefOfflineSubmissionObligationStatus $status,
        KsefLatarniaEvidenceCoverage $coverage = KsefLatarniaEvidenceCoverage::Complete,
    ): KsefOfflineSubmissionObligation {
        return new KsefOfflineSubmissionObligation(
            status: $status,
            baseDeadline: CarbonImmutable::parse('2026-09-07'),
            effectiveDeadline: CarbonImmutable::parse('2026-09-07'),
            reason: KsefOfflineSubmissionObligationReason::Offline24Base,
            evidenceCoverage: $coverage,
            appliedEventIds: [],
            appliedMessageIds: [],
            lastSubmissionStatus: null,
            evaluatedAt: CarbonImmutable::parse('2026-09-04T10:00:00Z'),
        );
    }
}
