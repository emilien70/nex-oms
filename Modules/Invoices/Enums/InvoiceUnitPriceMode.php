<?php

namespace Modules\Invoices\Enums;

enum InvoiceUnitPriceMode: string
{
    case Gross = 'gross';
    case Net = 'net';

    public function label(): string
    {
        return match ($this) {
            self::Gross => 'Brutto',
            self::Net => 'Netto',
        };
    }
}
