<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefInvoiceVerificationLinkBuilder;
use Tests\TestCase;

class KsefInvoiceVerificationLinkBuilderTest extends TestCase
{
    public function test_each_environment_uses_its_official_invoice_verification_host(): void
    {
        foreach ([
            KsefEnvironment::Test->value => 'https://qr-test.ksef.mf.gov.pl',
            KsefEnvironment::Demo->value => 'https://qr-demo.ksef.mf.gov.pl',
            KsefEnvironment::Production->value => 'https://qr.ksef.mf.gov.pl',
        ] as $environment => $host) {
            $submission = new KsefInvoiceSubmission([
                'environment' => $environment,
                'seller_nip' => '1111111111',
                'invoice_hash' => 'UtQp9Gpc51y+u3xApZjIjgkpZ01js+J8KflSPW8WzIE=',
            ]);

            $this->assertSame(
                $host.'/invoice/1111111111/01-02-2026/UtQp9Gpc51y-u3xApZjIjgkpZ01js-J8KflSPW8WzIE',
                app(KsefInvoiceVerificationLinkBuilder::class)->build(
                    $submission,
                    CarbonImmutable::parse('2026-02-01'),
                ),
            );
        }
    }

    public function test_invalid_frozen_identity_or_hash_does_not_create_a_verification_link(): void
    {
        $builder = app(KsefInvoiceVerificationLinkBuilder::class);
        $issueDate = CarbonImmutable::parse('2026-02-01');

        foreach ([
            ['seller_nip' => '111', 'invoice_hash' => base64_encode(str_repeat('a', 32))],
            ['seller_nip' => '1111111111', 'invoice_hash' => 'NOT-A-SHA-256-HASH'],
        ] as $attributes) {
            $submission = new KsefInvoiceSubmission([
                'environment' => KsefEnvironment::Test,
                ...$attributes,
            ]);

            $this->assertNull($builder->build($submission, $issueDate));
        }
    }
}
