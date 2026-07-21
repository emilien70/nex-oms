<?php

namespace Modules\Integrations\InPost\Jobs\Concerns;

use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

trait UsesInPostApiMiddleware
{
    public function middleware(): array
    {
        $shipmentId = $this->shipment->getKey();

        return [
            (new WithoutOverlapping('inpost-shipment-'.$shipmentId))
                ->releaseAfter(5)
                ->expireAfter(120)
                ->shared(),
            new RateLimited('inpost-api'),
        ];
    }
}
