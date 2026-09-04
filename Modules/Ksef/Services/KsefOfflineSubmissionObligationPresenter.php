<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligation;
use Modules\Ksef\ValueObjects\KsefOfflineSubmissionObligationPresentation;

final class KsefOfflineSubmissionObligationPresenter
{
    public function present(
        KsefEnvironment $environment,
        KsefOfflineSubmissionObligation $obligation,
    ): KsefOfflineSubmissionObligationPresentation {
        $prefix = $obligation->procedure->label();
        $label = match ($obligation->status) {
            KsefOfflineSubmissionObligationStatus::Pending => $prefix.' · do '.$obligation->effectiveDeadline?->format('d.m.Y'),
            KsefOfflineSubmissionObligationStatus::DueToday => $prefix.' · termin dzisiaj',
            KsefOfflineSubmissionObligationStatus::Overdue => $prefix.' · po terminie',
            KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd => $prefix.' · trwa przerwa KSeF',
            KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd => $prefix.' · trwa awaria KSeF',
            KsefOfflineSubmissionObligationStatus::SubmittedPendingResult => $prefix.' · wysłano, oczekiwanie na wynik',
            KsefOfflineSubmissionObligationStatus::Fulfilled => $prefix.' · obowiązek wykonany',
            KsefOfflineSubmissionObligationStatus::TransmissionUncertain => $prefix.' · wynik wysyłki niepewny',
            KsefOfflineSubmissionObligationStatus::RejectedRemediationRequired => $prefix.' · dokument odrzucony',
            KsefOfflineSubmissionObligationStatus::TransportModeMismatch => $prefix.' · niezgodny tryb KSeF',
            KsefOfflineSubmissionObligationStatus::EvidenceUnavailable => $prefix.' · brak pełnych danych Latarni',
            KsefOfflineSubmissionObligationStatus::AmbiguousEventHistory => $prefix.' · niejednoznaczna historia Latarni',
            KsefOfflineSubmissionObligationStatus::SubmissionIntegrityError => $prefix.' · błąd integralności danych',
            KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure => $prefix.' · brak obowiązku wysyłki wg projekcji Latarni',
        };
        $variant = match ($obligation->status) {
            KsefOfflineSubmissionObligationStatus::Fulfilled => 'success',
            KsefOfflineSubmissionObligationStatus::Pending,
            KsefOfflineSubmissionObligationStatus::SubmittedPendingResult,
            KsefOfflineSubmissionObligationStatus::NotRequiredTotalFailure => 'info',
            KsefOfflineSubmissionObligationStatus::DueToday,
            KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd,
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
            $tooltip .= $obligation->baseDeadline === null
                ? '. Termin wymaga aktualnych danych Latarni.'
                : '. '.($obligation->procedure === KsefOfflineIssuanceProcedure::Offline24
                    ? 'Bazowy termin Offline24: '
                    : 'Bazowy termin: ').$obligation->baseDeadline->format('d.m.Y')
                    .'. Pełny termin wymaga aktualnych danych Latarni.';
        } elseif ($obligation->status === KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd
            && $obligation->baseDeadline === null) {
            $tooltip .= '. Termin zostanie wyznaczony po zakończeniu awarii KSeF.';
        } elseif ($obligation->status === KsefOfflineSubmissionObligationStatus::WaitingForUnavailabilityEnd
            && $obligation->baseDeadline === null) {
            $tooltip .= '. Termin zostanie wyznaczony po zakończeniu przerwy KSeF.';
        }

        return new KsefOfflineSubmissionObligationPresentation($label, $variant, $tooltip, $urgent);
    }
}
