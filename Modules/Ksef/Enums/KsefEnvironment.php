<?php

namespace Modules\Ksef\Enums;

enum KsefEnvironment: string
{
    case Test = 'test';
    case Demo = 'demo';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Środowisko testowe',
            self::Demo => 'Środowisko przedprodukcyjne',
            self::Production => 'Środowisko produkcyjne',
        };
    }

    public function notice(): string
    {
        return match ($this) {
            self::Test => 'Środowisko testowe. Używaj wyłącznie danych testowych.',
            self::Demo => 'Środowisko przedprodukcyjne. Służy do testowania integracji przed uruchomieniem produkcyjnym.',
            self::Production => 'Środowisko produkcyjne. Konfiguracja będzie używana do rzeczywistej komunikacji z KSeF.',
        };
    }
}
