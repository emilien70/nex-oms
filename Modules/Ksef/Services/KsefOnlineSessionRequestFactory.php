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
        return [
            'offlineMode' => false,
            'invoiceHash' => $submission->invoice_hash,
            'invoiceSize' => $submission->invoice_size,
            'encryptedInvoiceHash' => $encryption->encryptedInvoiceHash,
            'encryptedInvoiceSize' => $encryption->encryptedInvoiceSize,
            'encryptedInvoiceContent' => $encryption->encryptedInvoiceContent,
        ];
    }
}
