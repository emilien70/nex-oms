<?php

namespace Modules\Invoices\Enums;

enum CorrectionPaymentMethodSource: string
{
    case SourceInvoice = 'source_invoice';
    case None = 'none';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::SourceInvoice => 'Pobierz z faktury źródłowej',
            self::None => 'Nie wyświetlaj',
            self::Fixed => 'Stały sposób płatności',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SourceInvoice => 'Sposób płatności zostanie pobrany ze snapshotu faktury źródłowej.',
            self::None => 'Sposób płatności nie będzie prezentowany na korekcie.',
            self::Fixed => 'Dokument użyje sposobu płatności zapisanego w tej serii.',
        };
    }
}
