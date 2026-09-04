<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;
use Throwable;

class KsefSubmissionFollowUpProcessor
{
    public function __construct(
        private readonly KsefSubmissionFollowUpPolicy $policy,
        private readonly KsefSubmissionFollowUpRateLimiter $limiter,
        private readonly KsefInvoiceStatusFollowUpService $followUp,
        private readonly KsefOperationalEnvironmentPolicy $environments,
    ) {}

    public function handle(int $submissionId): void
    {
        if (! $this->transportAvailable()) {
            return;
        }

        $submission = KsefInvoiceSubmission::query()->find($submissionId);
        if ($submission === null || ! $this->environments->allows($submission->environment)) {
            return;
        }

        $action = $this->policy->action($submission, $submission->upo()->exists());
        if ($action === null) {
            $this->followUp->synchronizeSchedule($submission);

            return;
        }

        if ($submission->next_follow_up_at === null || $submission->next_follow_up_at->isFuture()) {
            return;
        }

        $wait = $this->limiter->reserve(
            $action,
            $submission->environment,
            (string) $submission->context_nip,
        );
        if ($wait !== null) {
            $this->deferForLimiter($submission, $wait);

            return;
        }

        $submission = $this->claim($submission, $action);
        if ($submission === null) {
            return;
        }

        $invoice = $submission->invoice()->firstOrFail();

        try {
            match ($action) {
                KsefSubmissionFollowUpPolicy::ACTION_STATUS => $this->followUp->refresh($invoice, $submission),
                KsefSubmissionFollowUpPolicy::ACTION_RECONCILE => $this->followUp->reconcile($invoice, $submission),
                KsefSubmissionFollowUpPolicy::ACTION_UPO => $this->followUp->fetchUpo($invoice, $submission),
            };
        } catch (KsefApiException $exception) {
            if ($exception->safeCode === 'ksef_follow_up_in_progress') {
                $this->followUp->recordFailure($submission, $exception);
            }
        } catch (Throwable $exception) {
            $this->followUp->recordUnexpectedFailure($submission);
            Log::error('Nieoczekiwany błąd automatycznej obsługi KSeF.', [
                'submission_id' => $submission->getKey(),
                'invoice_id' => $submission->invoice_id,
                'operation' => $action,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function transportAvailable(): bool
    {
        if (config('ksef.invoice_submission_enabled') !== true) {
            return false;
        }

        return KsefSetting::query()
            ->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->where('is_active', true)
            ->exists();
    }

    private function deferForLimiter(KsefInvoiceSubmission $submission, int $wait): void
    {
        DB::transaction(function () use ($submission, $wait): void {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if ($managed->next_follow_up_at === null || $managed->next_follow_up_at->isFuture()) {
                return;
            }

            $managed->forceFill([
                'next_follow_up_at' => CarbonImmutable::now('UTC')->addSeconds(max(1, $wait)),
            ])->save();
        }, 3);
    }

    private function claim(
        KsefInvoiceSubmission $submission,
        string $expectedAction,
    ): ?KsefInvoiceSubmission {
        return DB::transaction(function () use ($submission, $expectedAction): ?KsefInvoiceSubmission {
            $managed = KsefInvoiceSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());
            $action = $this->policy->action($managed, $managed->upo()->exists());

            if ($action !== $expectedAction
                || $managed->next_follow_up_at === null
                || $managed->next_follow_up_at->isFuture()) {
                if ($action === null && $managed->next_follow_up_at !== null) {
                    $managed->forceFill(['next_follow_up_at' => null])->save();
                }

                return null;
            }

            $attempts = $this->policy->attemptsForAction($managed, $action) + 1;
            $managed->forceFill([
                'follow_up_attempts' => $attempts,
                'follow_up_action' => $action,
                'last_follow_up_at' => CarbonImmutable::now('UTC'),
                'next_follow_up_at' => $this->policy->nextAttemptAt($attempts),
            ])->save();

            return $managed->refresh();
        }, 3);
    }
}
