<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;

final class KsefOfflineCertificateRemoteOperationPolicy
{
    public function allows(KsefEnvironment $environment): bool
    {
        return in_array($environment, [
            KsefEnvironment::Test,
            KsefEnvironment::Demo,
            KsefEnvironment::Production,
        ], true);
    }

    public function assertAllowed(KsefEnvironment $environment): void
    {
        if ($this->allows($environment)) {
            return;
        }

        throw new KsefApiException(
            'Wybrane środowisko nie obsługuje zdalnych operacji certyfikatów Offline.',
            'offline_certificate_production_blocked',
        );
    }
}
