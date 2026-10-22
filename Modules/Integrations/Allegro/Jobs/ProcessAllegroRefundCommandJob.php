<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Services\AllegroRefundCommandService;

class ProcessAllegroRefundCommandJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public int $commandId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-refund-command:'.$this->commandId;
    }

    public function handle(AllegroRefundCommandService $service): void
    {
        $service->process($this->commandId);
    }
}
