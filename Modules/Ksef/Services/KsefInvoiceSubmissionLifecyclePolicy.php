<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Enumerable;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefInvoiceSubmissionLifecyclePolicy
{
    /** @param Enumerable<int, KsefInvoiceSubmission> $submissions */
    public function allowsNewAttempt(Enumerable $submissions): bool
    {
        return $submissions->every(
            static fn (KsefInvoiceSubmission $submission): bool => $submission->status->allowsNewAttempt(),
        );
    }

    /** @param Enumerable<int, KsefInvoiceSubmission> $submissions */
    public function assertNewAttemptAllowed(Enumerable $submissions): void
    {
        if ($this->allowsNewAttempt($submissions)) {
            return;
        }

        if ($submissions->contains(
            static fn (KsefInvoiceSubmission $submission): bool => $submission->status->requiresReconciliation(),
        )) {
            throw new KsefApiException(
                'Najpierw ustal wynik poprzedniej transmisji KSeF. Nie wolno wysyłać Faktury ponownie w stanie niepewnym.',
                'ksef_submission_reconciliation_required',
            );
        }

        throw new KsefApiException(
            'Dla tej Faktury istnieje próba KSeF, która blokuje utworzenie kolejnej transmisji.',
            'ksef_submission_already_exists',
        );
    }
}
