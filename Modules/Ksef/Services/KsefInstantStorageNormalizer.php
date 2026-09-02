<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;

final class KsefInstantStorageNormalizer
{
    public function forStorage(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone((string) config('app.timezone'));
    }
}
