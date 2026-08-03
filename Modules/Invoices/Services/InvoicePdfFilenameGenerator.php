<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Models\Invoice;

class InvoicePdfFilenameGenerator
{
    private const INVOICE_LAYOUT_VERSION = 'v34';

    private const PROFORMA_LAYOUT_VERSION = 'v33';

    private const CORRECTION_LAYOUT_VERSION = 'v33';

    private const FALLBACK_LAYOUT_VERSION = 'v33';

    public function storagePath(Invoice $invoice): string
    {
        $filename = match (true) {
            $invoice->isInvoice() => 'invoice-'.self::INVOICE_LAYOUT_VERSION.'.pdf',
            $invoice->isProforma() => 'proforma-revision-'.$invoice->revision_number.'-'.self::PROFORMA_LAYOUT_VERSION.'.pdf',
            $invoice->isCorrection() => 'correction-'.self::CORRECTION_LAYOUT_VERSION.'.pdf',
            default => 'document-'.self::FALLBACK_LAYOUT_VERSION.'.pdf',
        };

        return 'invoices/'.$invoice->getKey().'/'.$filename;
    }

    public function downloadName(Invoice $invoice): string
    {
        $prefix = $invoice->isProforma() ? 'Proforma_' : '';
        $number = trim((string) $invoice->number);
        $safe = preg_replace('/[^\pL\pN._-]+/u', '-', str_replace(['/', '\\', ' '], ['-', '-', '_'], $number));
        $safe = trim((string) $safe, '.-_');

        return $prefix.($safe !== '' ? $safe : 'dokument-'.$invoice->getKey()).'.pdf';
    }
}
