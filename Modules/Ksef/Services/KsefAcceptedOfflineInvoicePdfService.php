<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;

final class KsefAcceptedOfflineInvoicePdfService
{
    public function __construct(
        private readonly KsefOfflineSubmissionIntegrityService $integrity,
        private readonly KsefOfflineTechnicalCorrectionIntegrityService $technicalCorrectionIntegrity,
        private readonly KsefOfflinePresentationDataExtractor $presentations,
        private readonly KsefOfflinePresentationPdfRenderer $renderer,
        private readonly KsefOfflinePdfFilenameGenerator $filenames,
        private readonly KsefNumberValidator $ksefNumbers,
    ) {}

    /** @return array{contents: string, filename: string} */
    public function document(
        Invoice $invoice,
        KsefOfflineIssuance $issuance,
        KsefInvoiceSubmission $submission,
    ): array {
        if ($issuance->invoice_id !== $invoice->getKey()
            || $submission->invoice_id !== $invoice->getKey()
            || $submission->offline_issuance_id !== $issuance->getKey()) {
            throw new KsefApiException(
                'Zaakceptowana próba nie należy do wskazanego wystawienia Offline.',
                'ksef_accepted_offline_submission_mismatch',
            );
        }

        $technicalArtifact = $submission->offline_technical_correction_id === null
            ? null
            : $this->technicalCorrectionIntegrity->linkedArtifact($submission);
        if ($technicalArtifact === null) {
            $this->integrity->linkedIssuance($submission);
        }
        $number = trim((string) $submission->ksef_number);

        if ($submission->status !== KsefInvoiceSubmissionStatus::Accepted
            || $submission->invoicing_mode !== KsefInvoicingMode::Offline
            || ! $submission->hasExpectedInvoicingMode()
            || ! $this->ksefNumbers->isValid($number)
            || ! str_starts_with($number, (string) $issuance->seller_nip.'-')) {
            throw new KsefApiException(
                'Finalny PDF jest zablokowany, ponieważ akceptacja KSeF nie odpowiada trybowi Offline.',
                'ksef_accepted_offline_presentation_not_allowed',
            );
        }

        $presentation = $technicalArtifact === null
            ? $this->presentations->extract($issuance)
            : $this->presentations->extractTechnical($issuance, $technicalArtifact);

        return [
            'contents' => $this->renderer->renderAcceptedOfflineInvoice($presentation, $number),
            'filename' => $this->filenames->acceptedOfflineInvoice($presentation->invoiceNumber),
        ];
    }
}
