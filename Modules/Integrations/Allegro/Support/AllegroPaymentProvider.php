<?php

namespace Modules\Integrations\Allegro\Support;

final class AllegroPaymentProvider
{
    public static function label(?string $provider): ?string
    {
        return $provider === 'AF' ? 'Allegro Finance' : $provider;
    }
}
