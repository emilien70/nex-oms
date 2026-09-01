<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefOfflineCertificateKeyType;

final readonly class KsefOfflineCertificateMaterial
{
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public string $certificateSerialNumber,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validUntil,
        public string $fingerprintSha256,
        public KsefOfflineCertificateKeyType $keyType,
        public int $keySize,
        public ?string $curve,
    ) {}
}
