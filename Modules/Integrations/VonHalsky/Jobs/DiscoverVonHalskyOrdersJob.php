<?php

namespace Modules\Integrations\VonHalsky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Support\OrderImportQueue;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderDiscoveryService;
use Throwable;

class DiscoverVonHalskyOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $accountId, public int $revision, public bool $manual = false)
    {
        $this->onQueue(OrderImportQueue::NAME);
    }

    public function uniqueId(): string
    {
        return 'von-halsky-discovery:'.$this->accountId.':'.$this->revision;
    }

    public function handle(VonHalskyOrderDiscoveryService $discovery): void
    {
        try {
            $discovery->discover($this->accountId, $this->revision, $this->manual);
        } catch (Throwable $exception) {
            $discovery->recordFailure($this->accountId, $this->revision, $exception, $this->manual);
        }
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('von-halsky-discovery:'.$this->accountId))->shared()->releaseAfter(15)->expireAfter(180)];
    }

    public function failed(?Throwable $exception): void
    {
        app(VonHalskyOrderDiscoveryService::class)->recordFailure($this->accountId, $this->revision, $exception, $this->manual);
    }
}
