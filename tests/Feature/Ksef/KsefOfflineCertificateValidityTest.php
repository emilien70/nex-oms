<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineCertificateKeyType;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Services\KsefInstantStorageNormalizer;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefOfflineCertificateValidityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('instantCases')]
    public function test_validity_instant_survives_sqlite_roundtrip(
        string $remote,
        string $expectedRaw,
        string $expectedLocal,
        string $expectedUtc,
    ): void {
        config()->set('app.timezone', 'Europe/Warsaw');

        $original = CarbonImmutable::parse($remote);
        $forStorage = app(KsefInstantStorageNormalizer::class)->forStorage($original);

        $certificate = KsefOfflineCertificate::query()->create([
            'environment' => KsefEnvironment::Test,
            'certificate_serial_number' => '08F20A5D352AE590',
            'label' => 'Offline validity roundtrip',
            'certificate_pem' => 'FAKE_ENCRYPTED_CERTIFICATE_SOURCE',
            'private_key_pem' => 'FAKE_ENCRYPTED_PRIVATE_KEY_SOURCE',
            'valid_from' => $forStorage,
            'valid_until' => $forStorage,
            'fingerprint_sha256' => str_repeat('A', 64),
            'key_type' => KsefOfflineCertificateKeyType::Rsa,
            'key_size' => 2048,
            'curve' => null,
        ]);

        $raw = DB::table('ksef_offline_certificates')->find($certificate->getKey());
        $reloaded = $certificate->fresh();

        $this->assertNotNull($raw);
        $this->assertInstanceOf(KsefOfflineCertificate::class, $reloaded);
        $this->assertSame($expectedRaw, $raw->valid_from);
        $this->assertSame($expectedRaw, $raw->valid_until);
        $this->assertSame($expectedLocal, $reloaded->valid_from->format('Y-m-d H:i:s P'));
        $this->assertSame($expectedLocal, $reloaded->valid_until->format('Y-m-d H:i:s P'));
        $this->assertSame($expectedUtc, $reloaded->valid_from->utc()->format('Y-m-d H:i:s P'));
        $this->assertSame($expectedUtc, $reloaded->valid_until->utc()->format('Y-m-d H:i:s P'));
        $this->assertSame($original->getTimestamp(), $reloaded->valid_from->getTimestamp());
        $this->assertSame($original->getTimestamp(), $reloaded->valid_until->getTimestamp());
    }

    public function test_real_offline_certificate_import_preserves_validity_instants_after_reload(): void
    {
        Http::preventStrayRequests();
        config()->set('app.timezone', 'Europe/Warsaw');
        $fixture = KsefCertificateFixtureFactory::offlineRsa();

        $certificate = app(KsefOfflineCertificateService::class)->import(
            KsefEnvironment::Test,
            'Offline import roundtrip',
            $fixture['certificate'],
            $fixture['private_key'],
            null,
        );

        $raw = DB::table('ksef_offline_certificates')->find($certificate->getKey());
        $reloaded = $certificate->fresh();
        $expectedFrom = CarbonImmutable::createFromTimestampUTC($fixture['valid_from'])
            ->setTimezone('Europe/Warsaw');
        $expectedUntil = CarbonImmutable::createFromTimestampUTC($fixture['valid_until'])
            ->setTimezone('Europe/Warsaw');

        $this->assertNotNull($raw);
        $this->assertInstanceOf(KsefOfflineCertificate::class, $reloaded);
        $this->assertSame($expectedFrom->format('Y-m-d H:i:s'), $raw->valid_from);
        $this->assertSame($expectedUntil->format('Y-m-d H:i:s'), $raw->valid_until);
        $this->assertSame($fixture['valid_from'], $reloaded->valid_from->utc()->getTimestamp());
        $this->assertSame($fixture['valid_until'], $reloaded->valid_until->utc()->getTimestamp());
        Http::assertNothingSent();
    }

    public static function instantCases(): array
    {
        return [
            'summer UTC+2' => [
                '2026-08-26T10:00:00Z',
                '2026-08-26 12:00:00',
                '2026-08-26 12:00:00 +02:00',
                '2026-08-26 10:00:00 +00:00',
            ],
            'winter UTC+1' => [
                '2026-01-15T10:00:00Z',
                '2026-01-15 11:00:00',
                '2026-01-15 11:00:00 +01:00',
                '2026-01-15 10:00:00 +00:00',
            ],
            'DST transition' => [
                '2026-03-29T01:30:00Z',
                '2026-03-29 03:30:00',
                '2026-03-29 03:30:00 +02:00',
                '2026-03-29 01:30:00 +00:00',
            ],
            'explicit offset' => [
                '2026-08-26T12:00:00+02:00',
                '2026-08-26 12:00:00',
                '2026-08-26 12:00:00 +02:00',
                '2026-08-26 10:00:00 +00:00',
            ],
        ];
    }
}
