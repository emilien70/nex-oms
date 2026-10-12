<?php

namespace Modules\Integrations\PayNow\Enums;

enum PayNowConnectionTestStatus: string
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
