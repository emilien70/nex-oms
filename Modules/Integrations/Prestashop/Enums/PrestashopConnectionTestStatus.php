<?php

namespace Modules\Integrations\Prestashop\Enums;

enum PrestashopConnectionTestStatus: string
{
    case Success = 'success';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Połączenie działa poprawnie',
            self::Error => 'Błąd połączenia',
        };
    }
}
