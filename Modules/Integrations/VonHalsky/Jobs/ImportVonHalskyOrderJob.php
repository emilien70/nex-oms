<?php

namespace Modules\Integrations\VonHalsky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderProcessingService;

class ImportVonHalskyOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $accountId, public int $revision, public int $attemptId, public bool $manual = false)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'von-halsky-import:'.$this->attemptId.':'.$this->revision;
    }

    public function handle(VonHalskyOrderProcessingService $processing): void
    {
        $processing->process($this->accountId, $this->revision, $this->attemptId, $this->manual);
    }
}
