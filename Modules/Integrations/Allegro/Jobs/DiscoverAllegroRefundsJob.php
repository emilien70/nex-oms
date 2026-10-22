<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroRefundSyncService;
use Throwable;

class DiscoverAllegroRefundsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public int $accountId, public int $revision)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-refunds:'.$this->accountId.':'.$this->revision;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('allegro-refunds-account:'.$this->accountId))->shared()->dontRelease()->expireAfter(120)];
    }

    public function handle(AllegroRefundSyncService $sync): void
    {
        try {
            $sync->discover($this->accountId, $this->revision);
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AllegroAccount::query()->whereKey($this->accountId)->where('refunds_revision', $this->revision)
            ->update(['refunds_error' => 'Nie udało się pobrać refundacji. Zachowano poprzednie dane; odczyt zostanie ponowiony.', 'refunds_retry_after' => now()->addMinutes(5)]);
    }
}
