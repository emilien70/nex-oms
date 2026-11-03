<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Models\KsefSetting;

class InvoicePdfFilenameGenerator
{
    private const INVOICE_LAYOUT_VERSION = 'v47';

    private const PROFORMA_LAYOUT_VERSION = 'v36';

    private const CORRECTION_LAYOUT_VERSION = 'v47';

    private const FALLBACK_LAYOUT_VERSION = 'v33';

    public function storagePath(Invoice $invoice): string
    {
        $filename = match (true) {
            $invoice->isInvoice() => 'invoice-'.self::INVOICE_LAYOUT_VERSION.'.pdf',
            $invoice->isProforma() => 'proforma-'.self::PROFORMA_LAYOUT_VERSION.'.pdf',
            $invoice->isCorrection() => 'correction-'.self::CORRECTION_LAYOUT_VERSION.'.pdf',
            default => 'document-'.self::FALLBACK_LAYOUT_VERSION.'.pdf',
        };

        if ($invoice->isInvoice() || $invoice->isCorrection()) {
            $environment = KsefSetting::query()
                ->where('singleton_key', KsefSetting::SINGLETON_KEY)
                ->first(['environment'])?->environment;
            $filename = substr($filename, 0, -4).'-ksef-'.($environment?->value ?? 'none').'.pdf';
        }

        return 'invoices/'.$invoice->getKey().'/'.$filename;
    }

    public function downloadName(Invoice $invoice): string
    {
        $prefix = match (true) {
            $invoice->isInvoice() => 'Faktura ',
            $invoice->isCorrection() => 'Korekta ',
            $invoice->isProforma() => 'Proforma ',
            default => 'Dokument ',
        };

        return $prefix.$this->safeNumber($invoice).'.pdf';
    }

    public function legacyDownloadName(Invoice $invoice): string
    {
        return ($invoice->isProforma() ? 'Proforma_' : '').$this->safeNumber($invoice).'.pdf';
    }

    private function safeNumber(Invoice $invoice): string
    {
        $number = trim((string) $invoice->number);
        $safe = preg_replace('/[^\pL\pN._-]+/u', '-', str_replace(['/', '\\', ' '], ['-', '-', '_'], $number));
        $safe = trim((string) $safe, '.-_');

        return $safe !== '' ? $safe : 'dokument-'.$invoice->getKey();
    }
}
