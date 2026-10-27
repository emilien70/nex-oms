<?php

namespace Tests\Support;

use Modules\CustomerPortal\Models\PortalLink;

trait BuildsCustomerPortalRoutes
{
    private function portalRoute(string $name, array|string $parameters): string
    {
        $parameters = is_string($parameters) ? ['token' => $parameters] : $parameters;
        $order = PortalLink::query()->where('token_hash', hash('sha256', $parameters['token']))->value('order_id');

        return route($name, ['orderNumber' => $order ?? 1] + $parameters);
    }
}
