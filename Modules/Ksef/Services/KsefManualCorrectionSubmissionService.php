<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefManualCorrectionSubmissionService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
        private readonly InvoiceFinalizationService $finalization,
    ) {}

    public function submitFirstAttempt(
        Invoice $correction,
        KsefEnvironment $expectedEnvironment,
        ?string $expectedContextNip = null,
    ): KsefInvoiceSubmission {
        if (! $correction->isCorrection()) {
            throw new KsefApiException(
                'Ręczne przekazanie za pomocą tej operacji jest dostępne wyłącznie dla Korekty.',
                'ksef_submission_document_type_invalid',
            );
        }

        $submission = DB::transaction(function () use (
            $correction,
            $expectedEnvironment,
            $expectedContextNip,
        ): KsefInvoiceSubmission {
            $finalized = $correction->isFinalized()
                ? $correction
                : $this->finalization->finalize($correction);

            return $this->submissions->prepareCorrection(
                $finalized,
                $expectedEnvironment,
                true,
                $expectedContextNip,
            );
        }, 3);

        return $this->submissions->submit($submission);
    }
}
