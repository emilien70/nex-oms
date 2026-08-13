<?php

namespace Modules\Ksef\ValueObjects;

final readonly class KsefPublicKeyCertificate
{
    public function __construct(
        public string $certificate,
        public string $publicKeyId,
    ) {}
}
