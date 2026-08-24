<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;

class KsefOperationalEnvironmentPolicy
{
    public function allows(KsefEnvironment $environment): bool
    {
        return in_array($environment, [
            KsefEnvironment::Test,
            KsefEnvironment::Demo,
        ], true);
    }

    public function assertAllowed(KsefEnvironment $environment): void
    {
        if ($this->allows($environment)) {
            return;
        }

        throw new KsefApiException(
            'Operacyjny transport Faktur do środowiska produkcyjnego KSeF nie został jeszcze odblokowany.',
            'ksef_operational_environment_blocked',
        );
    }
}
