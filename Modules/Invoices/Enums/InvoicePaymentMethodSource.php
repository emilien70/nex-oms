<?php

namespace Modules\Invoices\Enums;

enum InvoicePaymentMethodSource: string
{
    case Order = 'order';
    case None = 'none';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Sposób płatności z zamówienia',
            self::None => 'Nie pokazuj sposobu płatności',
            self::Fixed => 'Stały sposób płatności z serii',
        };
    }
}
