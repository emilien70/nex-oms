<?php

namespace Modules\Integrations\VonHalsky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderStatusSyncService;
use Throwable;

class SyncVonHalskyOrderStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $accountId, public int $revision, public int $linkId,
        public string $requestedAt, public bool $manual = false)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'von-halsky-status:'.$this->accountId.':'.$this->revision.':'.$this->linkId;
    }

    public function handle(VonHalskyOrderStatusSyncService $statuses): void
    {
        try {
            $statuses->sync($this->accountId, $this->revision, $this->linkId, $this->requestedAt, $this->manual);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(VonHalskyOrderStatusSyncService::class)->recordFailure(
            $this->accountId, $this->revision, $this->linkId, $this->requestedAt, $exception, $this->manual,
        );
    }
}
