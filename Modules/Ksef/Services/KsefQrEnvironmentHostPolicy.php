<?php

namespace Modules\Ksef\Services;

use Modules\Ksef\Enums\KsefEnvironment;

final class KsefQrEnvironmentHostPolicy
{
    /**
     * Historical hosts stay in this allowlist when MF introduces a replacement host.
     *
     * @var array<string, list<string>>
     */
    private const HISTORICAL_HOSTS = [
        'test' => ['qr-test.ksef.mf.gov.pl'],
        'demo' => ['qr-demo.ksef.mf.gov.pl'],
        'production' => ['qr.ksef.mf.gov.pl'],
    ];

    public function allows(KsefEnvironment $environment, string $host): bool
    {
        return in_array($host, self::HISTORICAL_HOSTS[$environment->value] ?? [], true);
    }
}
