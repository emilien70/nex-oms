<?php

namespace Modules\Integrations\VonHalsky\Enums;

enum VonHalskyEnvironment: string
{
    case Stage = 'stage';
    case Production = 'production';

    public function label(): string
    {
        return $this === self::Stage ? 'Stage (testowe)' : 'Produkcja';
    }

    public function apiUrl(): string
    {
        return $this === self::Stage
            ? 'https://stage-api.inpost-group.com/inpsa'
            : 'https://api.inpost-group.com/inpsa';
    }

    public function tokenUrl(): string
    {
        return $this === self::Stage
            ? 'https://stage-account.inpost-group.com/oauth2/token'
            : 'https://account.inpost-group.com/oauth2/token';
    }
}
