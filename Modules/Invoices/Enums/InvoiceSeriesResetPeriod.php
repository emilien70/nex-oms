<?php

namespace Modules\Invoices\Enums;

enum InvoiceSeriesResetPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Miesięcznie',
            self::Yearly => 'Rocznie',
            self::None => 'Bez resetowania',
        };
    }
}
