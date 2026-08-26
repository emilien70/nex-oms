<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\Log;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Throwable;

class KsefAutomaticInvoiceSubmissionProcessor
{
    public function __construct(
        private readonly KsefAutomaticInvoiceSubmissionPolicy $policy,
        private readonly KsefManualInvoiceSubmissionService $manualSubmissions,
        private readonly KsefInvoiceStatusFollowUpService $statusFollowUp,
    ) {}

    public function handle(
        int $invoiceId,
        KsefEnvironment $expectedEnvironment,
        string $expectedContextNip,
    ): void {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null || ! $this->policy->allows(
            $invoice,
            $expectedEnvironment,
            $expectedContextNip,
        )) {
            return;
        }

        try {
            $submission = $this->manualSubmissions->submitFirstAttempt(
                $invoice,
                $expectedEnvironment,
                $expectedContextNip,
            );
        } catch (KsefApiException $exception) {
            if ($exception->safeCode === 'ksef_submission_first_attempt_already_exists') {
                return;
            }

            throw $exception;
        }

        try {
            $this->statusFollowUp->refresh($invoice, $submission);
        } catch (KsefApiException) {
            // The invoice was sent; the existing follow-up schedule keeps the durable state.
        } catch (Throwable $exception) {
            $this->statusFollowUp->recordUnexpectedFailure($submission);

            Log::error('Nieoczekiwany błąd pierwszego sprawdzenia statusu KSeF.', [
                'invoice_id' => $invoice->getKey(),
                'submission_id' => $submission->getKey(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}
