<?php

namespace App\Enums;

enum EmailTemplateDocumentType: string
{
    case InvoicePdf = 'invoice_pdf';
    case ProformaPdf = 'proforma_pdf';
    case LatestCorrectionPdf = 'latest_correction_pdf';

    public function label(): string
    {
        return match ($this) {
            self::InvoicePdf => 'Faktura VAT [PDF]',
            self::ProformaPdf => 'Pro forma [PDF]',
            self::LatestCorrectionPdf => 'Ostatnia Korekta [PDF]',
        };
    }
}
