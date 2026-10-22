<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Services\AllegroCommissionRefundService;

class ProcessAllegroCommissionRefundJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(public int $claimId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-commission-claim:'.$this->claimId;
    }

    public function handle(AllegroCommissionRefundService $service): void
    {
        $service->process($this->claimId);
    }
}
