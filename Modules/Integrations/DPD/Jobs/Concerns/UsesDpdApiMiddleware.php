<?php

namespace Modules\Integrations\DPD\Jobs\Concerns;

use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

trait UsesDpdApiMiddleware
{
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('dpd-shipment-'.$this->shipment->getKey()))
                ->releaseAfter(5)
                ->expireAfter(120)
                ->shared(),
            new RateLimited('dpd-api'),
        ];
    }
}
