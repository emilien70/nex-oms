<?php

namespace Modules\Integrations\NexPrinter\Enums;

enum NexPrinterClientVersionState: string
{
    case Current = 'current';
    case Outdated = 'outdated';
    case Preview = 'preview';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Aktualna',
            self::Outdated => 'Dostępna aktualizacja',
            self::Preview => 'Wersja testowa',
            self::Unknown => 'Nieznana',
        };
    }
}
