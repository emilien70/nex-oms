<?php

namespace Modules\Invoices\Enums;

enum InvoiceShippingVatMode: string
{
    case HighestItem = 'highest_item';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::HighestItem => 'Najwyższa stawka z pozycji dokumentu',
            self::Fixed => 'Stała stawka z serii',
        };
    }
}
