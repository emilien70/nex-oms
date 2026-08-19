<?php

namespace Modules\Ksef\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class KsefOnlineSessionOpenResult
{
    public function __construct(
        public string $referenceNumber,
        public CarbonImmutable $validUntil,
    ) {}
}
