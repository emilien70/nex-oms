<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Modules\Ksef\Services\KsefPublicKeyResolver;
use Tests\TestCase;

class KsefPublicKeyResolverTest extends TestCase
{
    public function test_it_selects_the_newest_current_token_encryption_key(): void
    {
        $now = CarbonImmutable::parse('2026-08-13T12:00:00Z');
        $certificate = app(KsefPublicKeyResolver::class)->resolve([
            $this->certificate('expired', 'KsefTokenEncryption', '2025-01-01', '2026-01-01'),
            $this->certificate('future', 'KsefTokenEncryption', '2027-01-01', '2028-01-01'),
            $this->certificate('symmetric', 'SymmetricKeyEncryption', '2026-01-01', '2027-01-01'),
            $this->certificate('older-current', 'KsefTokenEncryption', '2026-01-01', '2027-01-01'),
            $this->certificate('newest-current', 'KsefTokenEncryption', '2026-08-01', '2027-01-01'),
        ], $now);

        $this->assertSame('newest-current', $certificate->publicKeyId);
        $this->assertSame('certificate-newest-current', $certificate->certificate);
    }

    private function certificate(
        string $id,
        string $usage,
        string $validFrom,
        string $validTo,
    ): array {
        return [
            'certificate' => 'certificate-'.$id,
            'publicKeyId' => $id,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
            'usage' => [$usage],
        ];
    }
}
