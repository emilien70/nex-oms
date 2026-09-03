<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\ValueObjects\KsefOnlineSessionEncryptionData;

class KsefOnlineSessionRequestFactory
{
    public function openSession(KsefOnlineSessionEncryptionData $encryption): array
    {
        return [
            'formCode' => [
                'systemCode' => 'FA (3)',
                'schemaVersion' => '1-0E',
                'value' => 'FA',
            ],
            'encryption' => [
                'encryptedSymmetricKey' => $encryption->encryptedSymmetricKey,
                'initializationVector' => $encryption->initializationVector,
                'publicKeyId' => $encryption->publicKeyId,
            ],
        ];
    }

    public function sendInvoice(
        KsefInvoiceSubmission $submission,
        KsefOnlineSessionEncryptionData $encryption,
    ): array {
        return $this->invoice($submission, $encryption, false);
    }

    public function sendOfflineInvoice(
        KsefInvoiceSubmission $submission,
        KsefOnlineSessionEncryptionData $encryption,
    ): array {
        return $this->invoice($submission, $encryption, true);
    }

    private function invoice(
        KsefInvoiceSubmission $submission,
        KsefOnlineSessionEncryptionData $encryption,
        bool $offlineMode,
    ): array {
        return [
            'offlineMode' => $offlineMode,
            'invoiceHash' => $submission->invoice_hash,
            'invoiceSize' => $submission->invoice_size,
            'encryptedInvoiceHash' => $encryption->encryptedInvoiceHash,
            'encryptedInvoiceSize' => $encryption->encryptedInvoiceSize,
            'encryptedInvoiceContent' => $encryption->encryptedInvoiceContent,
        ];
    }
}
