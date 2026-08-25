<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefInvoiceUpo;

class KsefInvoiceStatusFollowUpService
{
    public function __construct(
        private readonly KsefInvoiceSubmissionService $submissions,
        private readonly KsefInvoiceUpoService $upos,
        private readonly InvoicePdfStorage $pdfStorage,
        private readonly KsefSubmissionFollowUpPolicy $policy,
    ) {}

    public function refresh(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceSubmission {
        return $this->withLock($submission, function () use ($invoice, $submission): KsefInvoiceSubmission {
            try {
                $result = $this->fetchUpoIfAccepted(
                    $invoice,
                    $this->submissions->refreshStatus($submission),
                );
                $this->synchronizeSchedule($result);

                return $result;
            } catch (KsefApiException $exception) {
                $this->recordFailure($submission, $exception);

                throw $exception;
            }
        });
    }

    public function reconcile(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceSubmission {
        return $this->withLock($submission, function () use ($invoice, $submission): KsefInvoiceSubmission {
            try {
                $result = $this->fetchUpoIfAccepted(
                    $invoice,
                    $this->submissions->reconcile($submission),
                );
                $this->synchronizeSchedule($result);

                return $result;
            } catch (KsefApiException $exception) {
                $this->recordFailure($submission, $exception);

                throw $exception;
            }
        });
    }

    public function fetchUpo(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
    ): KsefInvoiceUpo {
        return $this->withLock($submission, function () use ($invoice, $submission): KsefInvoiceUpo {
            try {
                $upo = $this->upos->fetch($invoice, $submission);
                $this->synchronizeSchedule($submission);

                return $upo;
            } catch (KsefApiException $exception) {
                $this->recordFailure($submission, $exception);

                throw $exception;
            }
        });
    }

    public function synchronizeSchedule(KsefInvoiceSubmission $submission): void
    {
        DB::transaction(function () use ($submission): void {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());
            $action = $this->policy->action($managed, $managed->upo()->exists());

            $managed->forceFill([
                'next_follow_up_at' => $action === null
                    ? null
                    : $this->policy->nextAttemptAt($managed->follow_up_attempts),
                'last_follow_up_error_code' => null,
                'last_follow_up_error_message' => null,
            ])->save();
        }, 3);
    }

    public function recordFailure(
        KsefInvoiceSubmission $submission,
        KsefApiException $exception,
    ): void {
        DB::transaction(function () use ($submission, $exception): void {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());
            $action = $this->policy->action($managed, $managed->upo()->exists());
            $retry = $action !== null && $this->policy->isTransient($exception);

            $managed->forceFill([
                'next_follow_up_at' => $retry
                    ? $this->policy->nextAttemptAt(
                        $managed->follow_up_attempts,
                        $exception->retryAfterSeconds,
                    )
                    : null,
                'last_follow_up_error_code' => $exception->safeCode,
                'last_follow_up_error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();
        }, 3);
    }

    public function recordUnexpectedFailure(KsefInvoiceSubmission $submission): void
    {
        DB::transaction(function () use ($submission): void {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            $managed->forceFill([
                'next_follow_up_at' => null,
                'last_follow_up_error_code' => 'ksef_follow_up_failed',
                'last_follow_up_error_message' => 'Nie udało się automatycznie dokończyć obsługi KSeF.',
            ])->save();
        }, 3);
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

    private function withLock(KsefInvoiceSubmission $submission, callable $operation): mixed
    {
        $lock = Cache::lock(
            'ksef-submission-follow-up:'.$submission->getKey(),
            max(30, (int) config('ksef.follow_up.lock_seconds', 120)),
        );

        if (! $lock->get()) {
            throw new KsefApiException(
                'Obsługa tej próby KSeF już trwa. Spróbuj ponownie za chwilę.',
                'ksef_follow_up_in_progress',
            );
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
