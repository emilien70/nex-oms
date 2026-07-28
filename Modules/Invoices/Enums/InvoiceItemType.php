<?php

namespace Modules\Invoices\Enums;

enum InvoiceItemType: string
{
    case Product = 'product';
    case Shipping = 'shipping';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Produkt',
            self::Shipping => 'Dostawa',
            self::Custom => 'Pozycja własna',
        };
    }
}
