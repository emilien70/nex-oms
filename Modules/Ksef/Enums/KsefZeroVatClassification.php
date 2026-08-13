<?php

namespace Modules\Ksef\Enums;

enum KsefZeroVatClassification: string
{
    case Wdt = 'wdt';
    case Export = 'export';
    case Domestic = 'domestic';

    public function label(): string
    {
        return match ($this) {
            self::Wdt => 'WDT',
            self::Export => 'Eksport towarów',
            self::Domestic => 'Sprzedaż krajowa 0%',
        };
    }
}
