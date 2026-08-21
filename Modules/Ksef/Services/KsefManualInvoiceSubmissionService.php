<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;

class KsefManualInvoiceSubmissionService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
    ) {}

    public function submitFirst(Invoice $invoice): KsefInvoiceSubmission
    {
        $submission = DB::transaction(function () use ($invoice): KsefInvoiceSubmission {
            $managed = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            if ($settings !== null) {
                $attemptExists = KsefInvoiceSubmission::query()
                    ->where('invoice_id', $managed->getKey())
                    ->where('environment', $settings->environment->value)
                    ->lockForUpdate()
                    ->exists();

                if ($attemptExists) {
                    throw new KsefApiException(
                        'Dla tej Faktury istnieje już próba KSeF w bieżącym środowisku. Ponowienie nie jest dostępne w tym workflow.',
                        'ksef_manual_submission_retry_not_available',
                    );
                }
            }

            return $this->submissions->prepare($managed);
        }, 3);

        return $this->submissions->submit($submission);
    }
}
