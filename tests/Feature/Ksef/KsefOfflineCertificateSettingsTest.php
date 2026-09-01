<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineCertificateKeyType;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefOfflineCertificateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_ui_expose_separate_offline_certificate_domain(): void
    {
        Http::preventStrayRequests();

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'offline-certificates']));

        $response
            ->assertOk()
            ->assertSeeText('Certyfikaty Offline')
            ->assertSeeText('Import certyfikatu Offline')
            ->assertSee('name="_token"', false)
            ->assertSee('name="offline_certificate"', false)
            ->assertSee('name="offline_private_key"', false)
            ->assertSee('name="offline_private_key_passphrase"', false)
            ->assertSeeText('Hasło jest używane tylko podczas importu i nie jest zapisywane.');

        $this->assertTrue(Schema::hasColumns('ksef_offline_certificates', [
            'environment',
            'certificate_serial_number',
            'certificate_pem',
            'private_key_pem',
            'valid_from',
            'valid_until',
            'fingerprint_sha256',
            'key_type',
            'key_size',
            'curve',
        ]));
        $this->assertTrue(Schema::hasColumns('ksef_offline_certificate_selections', [
            'environment',
            'offline_certificate_id',
        ]));
        Http::assertNothingSent();
    }

    public function test_rsa_import_encrypts_material_preserves_serial_and_exposes_only_metadata(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineRsa(passphrase: 'FAKE_OFFLINE_IMPORT_PASSPHRASE');

        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload(
            $fixture,
            passphrase: 'FAKE_OFFLINE_IMPORT_PASSPHRASE',
        ))->assertSessionDoesntHaveErrors();

        $certificate = KsefOfflineCertificate::query()->firstOrFail();
        $this->assertSame(KsefEnvironment::Test, $certificate->environment);
        $this->assertSame('08F20A5D352AE590', $certificate->certificate_serial_number);
        $this->assertSame(KsefOfflineCertificateKeyType::Rsa, $certificate->key_type);
        $this->assertSame(2048, $certificate->key_size);
        $this->assertNull($certificate->curve);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $certificate->certificate_pem);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $certificate->private_key_pem);
        $this->assertStringNotContainsString('FAKE_OFFLINE_IMPORT_PASSPHRASE', $certificate->private_key_pem);
        $this->assertArrayNotHasKey('certificate_pem', $certificate->toArray());
        $this->assertArrayNotHasKey('private_key_pem', $certificate->toArray());

        $raw = DB::table('ksef_offline_certificates')->first();
        $this->assertStringNotContainsString('-----BEGIN CERTIFICATE-----', $raw->certificate_pem);
        $this->assertStringNotContainsString('-----BEGIN PRIVATE KEY-----', $raw->private_key_pem);
        $this->assertStringNotContainsString('FAKE_OFFLINE_IMPORT_PASSPHRASE', $raw->private_key_pem);
        $this->assertFalse(Schema::hasColumn('ksef_offline_certificates', 'passphrase'));

        $this->get(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->assertOk()
            ->assertSeeText('08F20A5D352AE590')
            ->assertSeeText('RSA 2048')
            ->assertDontSee('-----BEGIN CERTIFICATE-----')
            ->assertDontSee('-----BEGIN PRIVATE KEY-----')
            ->assertDontSee('FAKE_OFFLINE_IMPORT_PASSPHRASE');
    }

    public function test_der_certificate_and_encrypted_ec_private_key_are_accepted(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineEc(passphrase: 'FAKE_EC_PASSPHRASE');
        $fixture['certificate'] = KsefCertificateFixtureFactory::certificateDer($fixture['certificate']);

        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload(
            $fixture,
            ['environment' => 'demo', 'label' => 'Offline EC'],
            'FAKE_EC_PASSPHRASE',
        ))->assertSessionDoesntHaveErrors();

        $certificate = KsefOfflineCertificate::query()->firstOrFail();
        $this->assertSame(KsefEnvironment::Demo, $certificate->environment);
        $this->assertSame(KsefOfflineCertificateKeyType::Ec, $certificate->key_type);
        $this->assertSame(256, $certificate->key_size);
        $this->assertSame('P-256', $certificate->curve);
        $this->assertSame('Offline EC', $certificate->label);
    }

    public function test_mismatched_key_and_wrong_passphrase_are_rejected_without_persistence(): void
    {
        $certificate = KsefCertificateFixtureFactory::offlineRsa();
        $other = KsefCertificateFixtureFactory::offlineRsa(serial: 0x08F20A5D352AE592);

        $this->from(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->post(route('integrations.ksef.offline-certificates.store'), $this->payload([
                'certificate' => $certificate['certificate'],
                'private_key' => $other['private_key'],
            ]))
            ->assertSessionHasErrors('offline_private_key');

        $encrypted = KsefCertificateFixtureFactory::offlineRsa(
            serial: 0x08F20A5D352AE593,
            passphrase: 'CORRECT_FAKE_PASSPHRASE',
        );
        $this->from(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->post(route('integrations.ksef.offline-certificates.store'), $this->payload(
                $encrypted,
                passphrase: 'WRONG_FAKE_PASSPHRASE',
            ))
            ->assertSessionHasErrors('offline_private_key');

        $this->assertDatabaseCount('ksef_offline_certificates', 0);
    }

    public function test_authentication_usage_missing_usage_and_unsupported_keys_are_rejected(): void
    {
        $fixtures = [
            'authentication usage' => KsefCertificateFixtureFactory::offlineRsa(keyUsage: 'digitalSignature'),
            'missing usage' => KsefCertificateFixtureFactory::offlineRsa(
                serial: 0x08F20A5D352AE592,
                keyUsage: '',
            ),
            'mixed authentication and Offline usage' => KsefCertificateFixtureFactory::offlineRsa(
                serial: 0x08F20A5D352AE595,
                keyUsage: 'digitalSignature,nonRepudiation',
            ),
            'RSA 1024' => KsefCertificateFixtureFactory::offlineRsa(
                serial: 0x08F20A5D352AE593,
                bits: 1024,
            ),
            'EC P-384' => KsefCertificateFixtureFactory::offlineEc(
                serial: 0x08F20A5D352AE594,
                curve: 'secp384r1',
            ),
        ];

        foreach ($fixtures as $fixture) {
            $this->from(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
                ->post(route('integrations.ksef.offline-certificates.store'), $this->payload($fixture))
                ->assertSessionHasErrors();
        }

        $this->assertDatabaseCount('ksef_offline_certificates', 0);
    }

    public function test_expired_and_not_yet_valid_certificates_are_rejected(): void
    {
        $expired = KsefCertificateFixtureFactory::offlineRsa();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($expired['valid_until'])->addSecond());

        try {
            $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload($expired))
                ->assertSessionHasErrors('offline_certificate');
        } finally {
            CarbonImmutable::setTestNow();
        }

        $future = KsefCertificateFixtureFactory::offlineRsa(serial: 0x08F20A5D352AE592);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($future['valid_from'])->subSecond());

        try {
            $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload($future))
                ->assertSessionHasErrors('offline_certificate');
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertDatabaseCount('ksef_offline_certificates', 0);
    }

    public function test_malformed_material_and_invalid_serial_are_rejected(): void
    {
        $valid = KsefCertificateFixtureFactory::offlineRsa();

        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload([
            'certificate' => 'NOT A CERTIFICATE',
            'private_key' => $valid['private_key'],
        ]))->assertSessionHasErrors('offline_certificate');

        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload([
            'certificate' => $valid['certificate'],
            'private_key' => 'NOT A PRIVATE KEY',
        ]))->assertSessionHasErrors('offline_private_key');

        $invalidSerial = KsefCertificateFixtureFactory::offlineRsa(serial: 0x1234);
        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload($invalidSerial))
            ->assertSessionHasErrors('offline_certificate');

        $this->assertDatabaseCount('ksef_offline_certificates', 0);
    }

    public function test_duplicate_serial_is_blocked_per_environment_but_environments_are_independent(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineRsa();

        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload($fixture))
            ->assertSessionDoesntHaveErrors();
        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload($fixture))
            ->assertSessionHasErrors('offline_certificate');
        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload(
            $fixture,
            ['environment' => 'demo'],
        ))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('ksef_offline_certificates', 2);
    }

    public function test_preferred_selection_is_one_per_environment_and_rotation_preserves_old_certificate(): void
    {
        $first = $this->importFixture(KsefCertificateFixtureFactory::offlineRsa());
        $second = $this->importFixture(KsefCertificateFixtureFactory::offlineEc());

        $this->put(route('integrations.ksef.offline-certificates.prefer', $first), [
            'environment' => 'test',
        ])->assertSessionDoesntHaveErrors();
        $this->put(route('integrations.ksef.offline-certificates.prefer', $second), [
            'environment' => 'test',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('ksef_offline_certificates', 2);
        $this->assertDatabaseCount('ksef_offline_certificate_selections', 1);
        $this->assertDatabaseHas('ksef_offline_certificate_selections', [
            'environment' => 'test',
            'offline_certificate_id' => $second->getKey(),
        ]);
        $this->assertDatabaseHas('ksef_offline_certificates', ['id' => $first->getKey()]);
    }

    public function test_cross_environment_selection_is_blocked(): void
    {
        $certificate = $this->importFixture(KsefCertificateFixtureFactory::offlineRsa());

        $this->from(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->put(route('integrations.ksef.offline-certificates.prefer', $certificate), [
                'environment' => 'demo',
            ])
            ->assertSessionHasErrors('environment');

        $this->assertDatabaseCount('ksef_offline_certificate_selections', 0);
    }

    public function test_local_delete_clears_preference_without_claiming_remote_revocation(): void
    {
        $certificate = $this->importFixture(KsefCertificateFixtureFactory::offlineRsa());
        KsefOfflineCertificateSelection::query()->create([
            'environment' => 'test',
            'offline_certificate_id' => $certificate->getKey(),
        ]);

        $this->delete(route('integrations.ksef.offline-certificates.destroy', $certificate))
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->assertSessionHas('status', function (string $message): bool {
                return str_contains($message, 'nie został unieważniony w KSeF');
            });

        $this->assertDatabaseCount('ksef_offline_certificates', 0);
        $this->assertDatabaseCount('ksef_offline_certificate_selections', 0);
    }

    public function test_passphrase_is_not_flashed_after_validation_error(): void
    {
        $fixture = KsefCertificateFixtureFactory::offlineRsa(passphrase: 'FAKE_PASSPHRASE_DO_NOT_FLASH');

        $this->from(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->post(route('integrations.ksef.offline-certificates.store'), $this->payload(
                $fixture,
                ['label' => str_repeat('x', 121)],
                'FAKE_PASSPHRASE_DO_NOT_FLASH',
            ))
            ->assertSessionHasErrors('label');

        $this->assertArrayNotHasKey('offline_private_key_passphrase', session()->getOldInput());
        $this->get(route('integrations.ksef.edit', ['tab' => 'offline-certificates']))
            ->assertDontSee('FAKE_PASSPHRASE_DO_NOT_FLASH');
    }

    private function importFixture(array $fixture): KsefOfflineCertificate
    {
        $this->post(route('integrations.ksef.offline-certificates.store'), $this->payload($fixture))
            ->assertSessionDoesntHaveErrors();

        return KsefOfflineCertificate::query()
            ->where('certificate_serial_number', $fixture['serial'])
            ->firstOrFail();
    }

    private function payload(
        array $fixture,
        array $overrides = [],
        ?string $passphrase = null,
    ): array {
        return array_replace([
            'environment' => 'test',
            'label' => 'Certyfikat Offline',
            'offline_certificate' => UploadedFile::fake()->createWithContent(
                'offline-certificate.cer',
                $fixture['certificate'],
            ),
            'offline_private_key' => UploadedFile::fake()->createWithContent(
                'offline-private-key.pem',
                $fixture['private_key'],
            ),
            'offline_private_key_passphrase' => $passphrase,
        ], $overrides);
    }
}
