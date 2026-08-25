<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefInvoiceStatusFollowUpService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
        private readonly KsefInvoiceUpoService $upos,
        private readonly InvoicePdfStorage $pdfStorage,
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
            $this->pdfStorage->delete($invoice);
            $this->upos->fetch($invoice, $submission);
        }

        return $submission;
    }
}
