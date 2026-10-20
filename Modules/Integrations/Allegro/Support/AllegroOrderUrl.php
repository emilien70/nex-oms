<?php

namespace Modules\Integrations\Allegro\Support;

use Illuminate\Support\Str;

final class AllegroOrderUrl
{
    public static function make(string $environment, ?string $orderId): ?string
    {
        if ($orderId === null || ! Str::isUuid($orderId)) {
            return null;
        }

        $domain = match ($environment) {
            'production' => 'salescenter.allegro.com',
            'sandbox' => 'salescenter.allegro.com.allegrosandbox.pl',
            default => null,
        };

        return $domain !== null ? 'https://'.$domain.'/orders/'.strtolower($orderId) : null;
    }
}
