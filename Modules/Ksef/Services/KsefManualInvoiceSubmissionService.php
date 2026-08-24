<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;

class KsefManualInvoiceSubmissionService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
    ) {}

    public function submit(Invoice $invoice): KsefInvoiceSubmission
    {
        $submission = DB::transaction(function () use ($invoice): KsefInvoiceSubmission {
            $managed = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());

            $settings = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            return $this->submissions->prepare($managed);
        }, 3);

        return $this->submissions->submit($submission);
    }
}
