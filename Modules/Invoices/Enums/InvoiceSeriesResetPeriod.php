<?php

namespace Modules\Invoices\Enums;

enum InvoiceSeriesResetPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case None = 'none';
}
