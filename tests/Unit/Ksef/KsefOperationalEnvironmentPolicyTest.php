<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
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

        if ($allowed) {
            $policy->assertAllowed($environment);
            $this->addToAssertionCount(1);

            return;
        }

        try {
            $policy->assertAllowed($environment);
            $this->fail('Expected production operational environment block.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_operational_environment_blocked', $exception->safeCode);
            $this->assertSame(
                'Operacyjny transport Faktur do środowiska produkcyjnego KSeF nie został jeszcze odblokowany.',
                $exception->getMessage(),
            );
        }
    }

    public static function environmentCases(): array
    {
        return [
            'TEST' => [KsefEnvironment::Test, true],
            'DEMO' => [KsefEnvironment::Demo, true],
            'PRODUCTION' => [KsefEnvironment::Production, false],
        ];
    }
}
