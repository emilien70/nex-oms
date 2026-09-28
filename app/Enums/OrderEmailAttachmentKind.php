<?php

namespace App\Enums;

enum OrderEmailAttachmentKind: string
{
    case Upload = 'upload';
    case InvoicePdf = 'invoice_pdf';
    case ProformaPdf = 'proforma_pdf';
    case LatestCorrectionPdf = 'latest_correction_pdf';
}
