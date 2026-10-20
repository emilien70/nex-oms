<?php

namespace Modules\Integrations\Allegro\Support;

final class AllegroOfferUrl
{
    public static function make(string $environment, ?string $offerId): ?string
    {
        if ($offerId === null || preg_match('/^[0-9]+$/', $offerId) !== 1) {
            return null;
        }

        $domain = $environment === 'sandbox' ? 'allegro.pl.allegrosandbox.pl' : 'allegro.pl';

        return 'https://'.$domain.'/oferta/-'.$offerId;
    }
}
