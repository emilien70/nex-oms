<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;

final class KsefTokenValidityNormalizer
{
    public function parseRemote(string $value): CarbonImmutable
    {
        return $this->forStorage(CarbonImmutable::parse($value));
    }

    public function forStorage(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone((string) config('app.timezone'));
    }
}
