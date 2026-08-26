<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Jobs\KsefAutomaticInvoiceSubmissionJob;

class KsefAutomaticInvoiceSubmissionDispatcher
{
    public function __construct(
        private readonly KsefAutomaticInvoiceSubmissionPolicy $policy,
        private readonly KsefAutomaticInvoiceSubmissionRateLimiter $limiter,
    ) {}

    public function dispatchIfEligible(Invoice $invoice): bool
    {
        $snapshot = $this->policy->snapshotFor($invoice);

        if ($snapshot === null) {
            return false;
        }

        $delay = $this->limiter->reserveDelay(
            $snapshot['environment'],
            $snapshot['context_nip'],
        );

        KsefAutomaticInvoiceSubmissionJob::dispatch(
            (int) $invoice->getKey(),
            $snapshot['environment'],
            $snapshot['context_nip'],
        )->delay($delay)->afterCommit();

        return true;
    }
}
