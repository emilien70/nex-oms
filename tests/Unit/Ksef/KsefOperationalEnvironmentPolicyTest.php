<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Services\KsefOfflineCertificateRemoteOperationPolicy;
use Modules\Ksef\Services\KsefOperationalEnvironmentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KsefOperationalEnvironmentPolicyTest extends TestCase
{
    #[DataProvider('environmentCases')]
    public function test_manual_invoice_operations_share_one_environment_contract(
        KsefEnvironment $environment,
        bool $allowed,
    ): void {
        $policy = new KsefOperationalEnvironmentPolicy;

        foreach (['prepare/send', 'refresh', 'reconcile', 'UPO fetch'] as $operation) {
            $this->assertSame($allowed, $policy->allows($environment), $operation);
        }

        $policy->assertAllowed($environment);
        $this->assertContains($environment, $policy->allowedEnvironments());
        $certificates = new KsefOfflineCertificateRemoteOperationPolicy;
        $this->assertTrue($certificates->allows($environment));
        $certificates->assertAllowed($environment);
    }

    public function test_unknown_environment_is_rejected_before_policy_evaluation(): void
    {
        $this->assertNull(KsefEnvironment::tryFrom('unknown'));
        $this->expectException(\TypeError::class);
        (new KsefOperationalEnvironmentPolicy)->allows('production');
    }

    public static function environmentCases(): array
    {
        return [
            'TEST' => [KsefEnvironment::Test, true],
            'DEMO' => [KsefEnvironment::Demo, true],
            'PRODUCTION' => [KsefEnvironment::Production, true],
        ];
    }
}
