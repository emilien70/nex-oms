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
        $snapshot = $this->policy->snapshotFor($invoice);

        if ($snapshot === null) {
            return false;
        }

        KsefAutomaticInvoiceSubmissionJob::dispatch(
            (int) $invoice->getKey(),
            $snapshot['environment'],
            $snapshot['context_nip'],
        )->afterCommit();

        return true;
    }
}
