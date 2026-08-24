<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;

class KsefManualInvoiceSubmissionService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
    ) {}

    public function submit(Invoice $invoice): KsefInvoiceSubmission
    {
        return $this->submitPrepared($invoice);
    }

    public function submitFirstAttempt(
        Invoice $invoice,
        KsefEnvironment $expectedEnvironment,
    ): KsefInvoiceSubmission {
        return $this->submitPrepared($invoice, $expectedEnvironment, true);
    }

    private function submitPrepared(
        Invoice $invoice,
        ?KsefEnvironment $expectedEnvironment = null,
        bool $firstAttemptOnly = false,
    ): KsefInvoiceSubmission {
        $submission = DB::transaction(function () use (
            $invoice,
            $expectedEnvironment,
            $firstAttemptOnly,
        ): KsefInvoiceSubmission {
            $managed = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            if ($expectedEnvironment !== null
                && ($settings === null || $settings->environment !== $expectedEnvironment)) {
                throw new KsefApiException(
                    'Środowisko KSeF zmieniło się podczas operacji. Faktura nie została wysłana.',
                    'ksef_submission_environment_changed',
                );
            }

            return $this->submissions->prepare(
                $managed,
                $expectedEnvironment,
                $firstAttemptOnly,
            );
        }, 3);

        return $this->submissions->submit($submission);
    }
}
