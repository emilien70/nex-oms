<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;

final class KsefTokenValidityNormalizer
{
    public function __construct(
        private readonly KsefInstantStorageNormalizer $instantStorage,
    ) {}

    public function parseRemote(string $value): CarbonImmutable
    {
        return $this->forStorage(CarbonImmutable::parse($value));
    }

    public function forStorage(CarbonImmutable $instant): CarbonImmutable
    {
        return $this->instantStorage->forStorage($instant);
    }
}
