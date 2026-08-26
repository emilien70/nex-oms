<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefTokenValidityNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefTokenValidityNormalizerTest extends TestCase
{
    #[DataProvider('remoteValidityCases')]
    public function test_remote_validity_preserves_the_instant_in_the_application_timezone(
        string $remote,
        string $expectedLocal,
        string $expectedUtc,
    ): void {
        config()->set('app.timezone', 'Europe/Warsaw');

        $validUntil = app(KsefTokenValidityNormalizer::class)->parseRemote($remote);

        $this->assertSame('Europe/Warsaw', $validUntil->getTimezone()->getName());
        $this->assertSame($expectedLocal, $validUntil->format('Y-m-d H:i:s P'));
        $this->assertSame($expectedUtc, $validUntil->utc()->format('Y-m-d H:i:s P'));
        $this->assertSame(substr($expectedLocal, 0, 19), (new KsefCredential)->fromDateTime($validUntil));
    }

    public static function remoteValidityCases(): array
    {
        return [
            'summer UTC+2' => [
                '2026-08-26T10:00:00Z',
                '2026-08-26 12:00:00 +02:00',
                '2026-08-26 10:00:00 +00:00',
            ],
            'winter UTC+1' => [
                '2026-01-15T10:00:00Z',
                '2026-01-15 11:00:00 +01:00',
                '2026-01-15 10:00:00 +00:00',
            ],
            'explicit offset' => [
                '2026-08-26T12:00:00+02:00',
                '2026-08-26 12:00:00 +02:00',
                '2026-08-26 10:00:00 +00:00',
            ],
            'after DST transition' => [
                '2026-03-29T01:30:00Z',
                '2026-03-29 03:30:00 +02:00',
                '2026-03-29 01:30:00 +00:00',
            ],
        ];
    }
}
