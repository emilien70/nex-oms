<?php

namespace Modules\Ksef\Services;

final class KsefOfflinePdfFilenameGenerator
{
    public function offlineInvoice(string $invoiceNumber): string
    {
        return 'faktura-offline-'.$this->safeNumber($invoiceNumber).'.pdf';
    }

    public function transactionConfirmation(string $invoiceNumber): string
    {
        return 'potwierdzenie-transakcji-'.$this->safeNumber($invoiceNumber).'.pdf';
    }

    public function acceptedOfflineInvoice(string $invoiceNumber): string
    {
        return 'faktura-ksef-'.$this->safeNumber($invoiceNumber).'.pdf';
    }

    private function safeNumber(string $invoiceNumber): string
    {
        $safe = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            str_replace(['/', '\\', ' '], ['-', '-', '_'], trim($invoiceNumber)),
        );

        return trim((string) $safe, '.-_') ?: 'dokument';
    }
}
