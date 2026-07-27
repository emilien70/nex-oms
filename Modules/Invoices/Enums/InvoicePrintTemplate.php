<?php

namespace Modules\Invoices\Enums;

enum InvoicePrintTemplate: string
{
    case Standard = 'standard';

    public function label(): string
    {
        return 'Standardowy szablon wydruku';
    }
}
