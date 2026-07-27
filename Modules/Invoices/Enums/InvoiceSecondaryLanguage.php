<?php

namespace Modules\Invoices\Enums;

enum InvoiceSecondaryLanguage: string
{
    case Polish = 'pl';
    case English = 'en';

    public function label(): string
    {
        return match ($this) {
            self::Polish => 'Polski',
            self::English => 'Angielski',
        };
    }
}
