<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;
use Modules\Integrations\Allegro\Services\AllegroRefundSyncService;
use Throwable;

class SyncAllegroRefundJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public int $accountId, public int $revision, public int $refundId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-refund:'.$this->refundId.':'.$this->revision;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('allegro-refunds-account:'.$this->accountId))->shared()->dontRelease()->expireAfter(120)];
    }

    public function handle(AllegroRefundSyncService $sync): void
    {
        try {
            $sync->refresh($this->accountId, $this->revision, $this->refundId);
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AllegroPaymentRefund::query()->whereKey($this->refundId)->where('allegro_account_id', $this->accountId)
            ->whereHas('account', fn ($query) => $query->where('refunds_revision', $this->revision)->where('refunds_enabled', true))
            ->update(['sync_error' => 'Nie udało się sprawdzić refundacji. Zachowano poprzednie dane.', 'next_sync_at' => now()->addMinutes(5)]);
    }
}
