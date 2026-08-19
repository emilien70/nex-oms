<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefOnlineSessionEncryptionData
{
    public function __construct(
        public string $encryptedSymmetricKey,
        public string $initializationVector,
        public string $publicKeyId,
        public string $encryptedInvoiceContent,
        public string $encryptedInvoiceHash,
        public int $encryptedInvoiceSize,
        public string $cipherKey,
        public string $cipherIv,
    ) {}
}
