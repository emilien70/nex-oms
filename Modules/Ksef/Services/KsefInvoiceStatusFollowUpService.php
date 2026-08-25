<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefInvoiceStatusFollowUpService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
        private readonly KsefInvoiceUpoService $upos,
    ) {}

    public function refresh(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceSubmission {
        return $this->fetchUpoIfAccepted(
            $invoice,
            $this->submissions->refreshStatus($submission),
        );
    }

    public function reconcile(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceSubmission {
        return $this->fetchUpoIfAccepted(
            $invoice,
            $this->submissions->reconcile($submission),
        );
    }

    private function fetchUpoIfAccepted(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceSubmission {
        if ($submission->status === KsefInvoiceSubmissionStatus::Accepted) {
            $this->upos->fetch($invoice, $submission);
        }

        return $submission;
    }
}
