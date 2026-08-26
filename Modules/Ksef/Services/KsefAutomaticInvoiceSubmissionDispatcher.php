<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Jobs\KsefAutomaticInvoiceSubmissionJob;

class KsefAutomaticInvoiceSubmissionDispatcher
{
    public function __construct(
        private readonly KsefAutomaticInvoiceSubmissionPolicy $policy,
    ) {}

    public function dispatchIfEligible(Invoice $invoice): bool
    {
        $environment = $this->policy->environmentFor($invoice);

        if ($environment === null) {
            return false;
        }

        KsefAutomaticInvoiceSubmissionJob::dispatch(
            (int) $invoice->getKey(),
            $environment,
        )->afterCommit();

        return true;
    }
}
