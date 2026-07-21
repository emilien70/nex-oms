<?php

namespace Modules\Integrations\AllegroShipping\Jobs\Concerns;

use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

trait UsesAllegroShippingApiMiddleware
{
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('allegro-shipment-'.$this->shipment->getKey()))
                ->releaseAfter(5)->expireAfter(180)->shared(),
            new RateLimited('allegro-shipping-api'),
        ];
    }
}
