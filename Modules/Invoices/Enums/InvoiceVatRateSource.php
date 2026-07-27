<?php

namespace Modules\Invoices\Enums;

enum InvoiceVatRateSource: string
{
    case OrderItem = 'order_item';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::OrderItem => 'Stawka z pozycji zamówienia',
            self::Fixed => 'Stała stawka z serii',
        };
    }
}
