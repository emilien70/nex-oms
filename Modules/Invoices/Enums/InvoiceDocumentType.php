<?php

namespace Modules\Invoices\Enums;

enum InvoiceDocumentType: string
{
    case Invoice = 'invoice';
    case Proforma = 'proforma';
    case Correction = 'correction';
}
