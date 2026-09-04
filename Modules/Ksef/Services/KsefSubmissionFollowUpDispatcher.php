<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Jobs\KsefSubmissionFollowUpJob;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;

class KsefSubmissionFollowUpDispatcher
{
    public function dispatchScheduled(KsefInvoiceSubmission $submission): bool
    {
        $managed = KsefInvoiceSubmission::query()->find($submission->getKey());
        if ($managed === null || $managed->next_follow_up_at === null) {
            return false;
        }

        KsefSubmissionFollowUpJob::dispatch((int) $managed->getKey())
            ->delay($managed->next_follow_up_at);

        return true;
    }

    public function dispatchDue(): int
    {
        if (config('ksef.invoice_submission_enabled') !== true
            || ! KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->where('is_active', true)
                ->exists()) {
            return 0;
        }

        $ids = KsefInvoiceSubmission::query()
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', CarbonImmutable::now('UTC'))
            ->whereIn('environment', [
                KsefEnvironment::Test->value,
                KsefEnvironment::Demo->value,
            ])
            ->where(function ($query): void {
                $query->whereIn('status', [
                    KsefInvoiceSubmissionStatus::Submitted->value,
                    KsefInvoiceSubmissionStatus::Processing->value,
                    KsefInvoiceSubmissionStatus::Uncertain->value,
                ])->orWhere(function ($query): void {
                    $query->where('status', KsefInvoiceSubmissionStatus::Accepted->value)
                        ->whereDoesntHave('upo');
                });
            })
            ->orderBy('next_follow_up_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('ksef.follow_up.dispatch_batch_size', 20)))
            ->pluck('id');

        $ids->each(fn (int|string $id) => KsefSubmissionFollowUpJob::dispatch((int) $id));

        return $ids->count();
    }
}
