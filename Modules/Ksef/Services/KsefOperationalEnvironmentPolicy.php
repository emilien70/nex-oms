<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;

class KsefOperationalEnvironmentPolicy
{
    public function allows(KsefEnvironment $environment): bool
    {
        return in_array($environment, $this->allowedEnvironments(), true);
    }

    /** @return list<KsefEnvironment> */
    public function allowedEnvironments(): array
    {
        return [
            KsefEnvironment::Test,
            KsefEnvironment::Demo,
            KsefEnvironment::Production,
        ];
    }

    public function assertAllowed(KsefEnvironment $environment): void
    {
        if ($this->allows($environment)) {
            return;
        }

        throw new KsefApiException(
            'Wybrane środowisko nie obsługuje operacyjnego transportu Faktur do KSeF.',
            'ksef_operational_environment_blocked',
        );
    }
}
