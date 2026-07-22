<?php

namespace Modules\Invoices\Enums;

enum InvoiceSeriesSystemKey: string
{
    case Invoice = 'invoice';
    case Correction = 'correction';
    case Proforma = 'proforma';
}
