<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligation;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligationPresentation;

final class KsefOfflineSubmissionObligationPresenter
{
    public function present(
        KsefEnvironment $environment,
        KsefOfflineSubmissionObligation $obligation,
    ): KsefOfflineSubmissionObligationPresentation {
        $label = match ($obligation->status) {
            KsefOfflineSubmissionObligationStatus::Pending => 'Offline24 · do '.$obligation->effectiveDeadline?->format('d.m.Y'),
            KsefOfflineSubmissionObligationStatus::DueToday => 'Offline24 · termin dzisiaj',
            KsefOfflineSubmissionObligationStatus::Overdue => 'Offline24 · po terminie',
            KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd => 'Offline24 · trwa awaria KSeF',
            KsefOfflineSubmissionObligationStatus::SubmittedPendingResult => 'Offline24 · wysłano, oczekiwanie na wynik',
            KsefOfflineSubmissionObligationStatus::Fulfilled => 'Offline24 · obowiązek wykonany',
            KsefOfflineSubmissionObligationStatus::TransmissionUncertain => 'Offline24 · wynik wysyłki niepewny',
            KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired => 'Offline24 · dokument odrzucony',
            KsefOfflineSubmissionObligationStatus::TransportModeMismatch => 'Offline24 · niezgodny tryb KSeF',
            KsefOfflineSubmissionObligationStatus::EvidenceUnavailable => 'Offline24 · brak pełnych danych Latarni',
            KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory => 'Offline24 · niejednoznaczna historia Latarni',
            KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError => 'Offline24 · błąd integralności danych',
            KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure => 'Offline24 · brak obowiązku wysyłki wg projekcji Latarni',
        };
        $variant = match ($obligation->status) {
            KsefOfflineSubmissionObligationStatus::Fulfilled => 'success',
            KsefOfflineSubmissionObligationStatus::Pending,
            KsefOfflineSubmissionObligationStatus::SubmittedPendingResult,
            KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure => 'info',
            KsefOfflineSubmissionObligationStatus::DueToday,
            KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd,
            KsefOfflineSubmissionObligationStatus::EvidenceUnavailable => 'warning',
            default => 'danger',
        };
        $urgent = in_array($obligation->status, [
            KsefOfflineSubmissionObligationStatus::DueToday,
            KsefOfflineSubmissionObligationStatus::Overdue,
            KsefOfflineSubmissionObligationStatus::TransmissionUncertain,
            KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired,
            KsefOfflineSubmissionObligationStatus::TransportModeMismatch,
            KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory,
            KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError,
        ], true) || ($obligation->status === KsefOfflineSubmissionObligationStatus::EvidenceUnavailable
            && $environment !== KsefEnvironment::Demo);
        $tooltip = 'Stan wg danych Latarni na: '.$obligation->evaluatedAt
            ->setTimezone((string) config('app.timezone'))
            ->format('d.m.Y H:i');

        if ($obligation->evidenceCoverage !== KsefLatarniaEvidenceCoverage::Complete) {
            $tooltip .= '. Bazowy termin Offline24: '.$obligation->baseDeadline->format('d.m.Y')
                .'. Pełny termin wymaga aktualnych danych Latarni.';
        }

        return new KsefOfflineSubmissionObligationPresentation($label, $variant, $tooltip, $urgent);
    }
}
