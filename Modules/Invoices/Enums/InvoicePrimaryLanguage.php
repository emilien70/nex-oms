<?php

namespace Modules\Invoices\Enums;

enum InvoicePrimaryLanguage: string
{
    case BuyerCountry = 'buyer_country';
    case Polish = 'pl';
    case English = 'en';

    public function label(): string
    {
        return match ($this) {
            self::BuyerCountry => 'Zgodny z krajem kupującego',
            self::Polish => 'Polski',
            self::English => 'Angielski',
        };
    }
}
