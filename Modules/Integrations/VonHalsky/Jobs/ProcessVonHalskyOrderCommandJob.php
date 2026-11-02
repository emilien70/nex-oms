<?php

namespace Modules\Integrations\VonHalsky\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderCommandService;
use Throwable;

class ProcessVonHalskyOrderCommandJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $commandId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'von-halsky-order-command:'.$this->commandId;
    }

    public function handle(VonHalskyOrderCommandService $commands): void
    {
        try {
            $commands->process($this->commandId);
        } catch (Throwable $exception) {
            $commands->recordFailure($this->commandId, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(VonHalskyOrderCommandService::class)->recordFailure($this->commandId, $exception);
    }
}
