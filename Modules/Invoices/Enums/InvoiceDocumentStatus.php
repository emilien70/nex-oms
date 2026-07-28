<?php

namespace Modules\Invoices\Enums;

enum InvoiceDocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Szkic',
            self::Issued => 'Wystawiony',
        };
    }
}
