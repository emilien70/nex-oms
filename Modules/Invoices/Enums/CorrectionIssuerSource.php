<?php

namespace Modules\Invoices\Enums;

enum CorrectionIssuerSource: string
{
    case SourceInvoice = 'source_invoice';
    case Series = 'series';

    public function label(): string
    {
        return match ($this) {
            self::SourceInvoice => 'Pobierz z faktury źródłowej',
            self::Series => 'Użyj wystawiającego z serii korekt',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SourceInvoice => 'Osoba wystawiająca zostanie pobrana ze snapshotu faktury źródłowej.',
            self::Series => 'Dokument użyje osoby wystawiającej zapisanej w tej serii.',
        };
    }
}
