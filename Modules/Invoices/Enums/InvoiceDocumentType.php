<?php

namespace Modules\Invoices\Enums;

enum InvoiceDocumentType: string
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Faktura',
            self::Correction => 'Korekta',
            self::Proforma => 'Pro forma',
        };
    }

    public function defaultNumberFormat(): string
    {
        return match ($this) {
            self::Invoice => 'BL %N/%Y',
            self::Correction => 'BLK %N/%Y',
            self::Proforma => 'BLPF %N/%Y',
        };
    }
}
