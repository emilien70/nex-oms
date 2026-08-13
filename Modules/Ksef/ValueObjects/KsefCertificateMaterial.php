<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefCertificateMaterial
{
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public array $metadata,
    ) {}
}
