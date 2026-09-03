<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\KsefQrEnvironmentHostPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KsefQrEnvironmentHostPolicyTest extends TestCase
{
    #[DataProvider('hostCases')]
    public function test_only_official_hosts_for_the_exact_environment_are_allowed(
        KsefEnvironment $environment,
        string $host,
        bool $expected,
    ): void {
        $this->assertSame($expected, (new KsefQrEnvironmentHostPolicy)->allows($environment, $host));
    }

    public static function hostCases(): array
    {
        return [
            'TEST official host' => [KsefEnvironment::Test, 'qr-test.ksef.mf.gov.pl', true],
            'DEMO official host' => [KsefEnvironment::Demo, 'qr-demo.ksef.mf.gov.pl', true],
            'PRODUCTION official host' => [KsefEnvironment::Production, 'qr.ksef.mf.gov.pl', true],
            'TEST rejects DEMO' => [KsefEnvironment::Test, 'qr-demo.ksef.mf.gov.pl', false],
            'DEMO rejects TEST' => [KsefEnvironment::Demo, 'qr-test.ksef.mf.gov.pl', false],
            'PRODUCTION rejects malicious host' => [KsefEnvironment::Production, 'evil.example', false],
        ];
    }
}
