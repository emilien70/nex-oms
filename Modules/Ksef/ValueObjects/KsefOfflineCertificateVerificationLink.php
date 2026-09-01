<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefOfflineCertificateVerificationLink
{
    public function __construct(
        public string $url,
        public string $preSign,
        public string $invoiceHashBase64Url,
        public string $signatureBase64Url,
    ) {}
}
