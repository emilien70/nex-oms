<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefCertificateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_form_expose_certificate_authentication_without_live_test(): void
    {
        $response = $this->get(route('integrations.ksef.edit'));

        $response
            ->assertOk()
            ->assertSeeText('Token KSeF')
            ->assertSeeText('Certyfikat KSeF')
            ->assertSee('value="certificate"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="authentication_certificate"', false)
            ->assertSee('name="authentication_private_key"', false)
            ->assertSee('name="authentication_private_key_passphrase"', false)
            ->assertSee('data-ksef-certificate-file', false)
            ->assertSee('data-ksef-private-key-file', false);

        $this->assertTrue(Schema::hasColumns('ksef_credentials', [
            'authentication_certificate',
            'authentication_private_key',
        ]));
        $this->assertSame(
            ['token', 'certificate'],
            array_column(KsefAuthenticationMethod::cases(), 'value'),
        );
    }

    public function test_valid_certificate_pair_is_encrypted_hidden_and_exposes_only_safe_metadata(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa(passphrase: 'SECRET_IMPORT_PASSPHRASE');
        Http::preventStrayRequests();

        $this->put(route('integrations.ksef.update'), $this->certificatePayload(
            $fixture,
            passphrase: 'SECRET_IMPORT_PASSPHRASE',
        ))->assertSessionDoesntHaveErrors();

        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $this->assertSame(KsefAuthenticationMethod::Certificate, $credential->authentication_method);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $credential->authentication_certificate);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $credential->authentication_private_key);
        $this->assertStringNotContainsString('SECRET_IMPORT_PASSPHRASE', $credential->authentication_private_key);

        $raw = DB::table('ksef_credentials')->where('environment', 'test')->first();
        $this->assertStringNotContainsString('-----BEGIN CERTIFICATE-----', $raw->authentication_certificate);
        $this->assertStringNotContainsString('-----BEGIN PRIVATE KEY-----', $raw->authentication_private_key);
        $this->assertStringNotContainsString('SECRET_IMPORT_PASSPHRASE', $raw->authentication_private_key);
        $this->assertArrayNotHasKey('api_token', $credential->toArray());
        $this->assertArrayNotHasKey('authentication_certificate', $credential->toArray());
        $this->assertArrayNotHasKey('authentication_private_key', $credential->toArray());

        $response = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSeeText('Certyfikat skonfigurowany dla wybranego środowiska.')
            ->assertSee('RSA 2048', false)
            ->assertSee('Test połączenia używa zapisanej konfiguracji.', false)
            ->assertDontSee('SECRET_IMPORT_PASSPHRASE')
            ->assertDontSee('-----BEGIN CERTIFICATE-----')
            ->assertDontSee('-----BEGIN PRIVATE KEY-----');

        preg_match('/<button[^>]+data-ksef-test-button[^>]*>/s', $response->getContent(), $button);
        $this->assertArrayHasKey(0, $button);
        $this->assertStringNotContainsString('disabled', $button[0]);
    }

    public function test_certificate_maps_use_string_environment_keys_and_keep_environments_separate(): void
    {
        $testFixture = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($testFixture))
            ->assertSessionDoesntHaveErrors();

        $service = app(KsefSettingsService::class);
        $this->assertSame([
            'test' => true,
            'demo' => false,
            'production' => false,
        ], $service->certificateConfiguredByEnvironment());
        $this->assertSame([
            'test' => 'certificate',
            'demo' => 'token',
            'production' => 'token',
        ], $service->authenticationMethodByEnvironment());

        $demoFixture = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($demoFixture, [
            'environment' => 'demo',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame([
            'test' => true,
            'demo' => true,
            'production' => false,
        ], $service->certificateConfiguredByEnvironment());
        $this->assertSame([
            'test' => 'certificate',
            'demo' => 'certificate',
            'production' => 'token',
        ], $service->authenticationMethodByEnvironment());
        $this->assertNotSame(
            KsefCredential::query()->where('environment', 'test')->firstOrFail()->authentication_certificate,
            KsefCredential::query()->where('environment', 'demo')->firstOrFail()->authentication_certificate,
        );
    }

    public function test_both_files_are_required_and_upload_size_is_limited(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa();

        $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->payload([
                'authentication_method' => 'certificate',
                'authentication_certificate' => $this->upload('certificate.pem', $fixture['certificate']),
            ]))
            ->assertSessionHasErrors('authentication_private_key');

        $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->payload([
                'authentication_method' => 'certificate',
                'authentication_private_key' => $this->upload('private-key.pem', $fixture['private_key']),
            ]))
            ->assertSessionHasErrors('authentication_certificate');

        $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->payload([
                'authentication_method' => 'certificate',
                'authentication_certificate' => UploadedFile::fake()->create('oversized.cer', 129),
                'authentication_private_key' => $this->upload('private-key.pem', $fixture['private_key']),
            ]))
            ->assertSessionHasErrors('authentication_certificate');
    }

    public function test_invalid_replacement_does_not_modify_existing_pair_or_settings(): void
    {
        $original = KsefCertificateFixtureFactory::rsa();
        $replacement = KsefCertificateFixtureFactory::rsa();
        $wrongKey = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($original))
            ->assertSessionDoesntHaveErrors();
        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $certificateBefore = $credential->authentication_certificate;
        $privateKeyBefore = $credential->authentication_private_key;
        $settingsBefore = app(KsefSettingsService::class)->get()->getAttributes();

        $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->certificatePayload([
                'certificate' => $replacement['certificate'],
                'private_key' => $wrongKey['private_key'],
            ], ['name' => 'Nie zapisuj tej nazwy']))
            ->assertSessionHasErrors('authentication_private_key');

        $credential->refresh();
        $this->assertSame($certificateBefore, $credential->authentication_certificate);
        $this->assertSame($privateKeyBefore, $credential->authentication_private_key);
        $this->assertSame($settingsBefore, app(KsefSettingsService::class)->get()->getAttributes());
    }

    public function test_save_without_reupload_preserves_material_and_unrelated_runtime_state(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($fixture))
            ->assertSessionDoesntHaveErrors();
        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $certificateBefore = $credential->authentication_certificate;
        $privateKeyBefore = $credential->authentication_private_key;
        $this->fillRuntimeState($credential, 'CERTIFICATE');

        $this->put(route('integrations.ksef.update'), $this->payload([
            'authentication_method' => 'certificate',
            'include_gtu' => false,
        ]))->assertSessionDoesntHaveErrors();

        $credential->refresh();
        $this->assertSame($certificateBefore, $credential->authentication_certificate);
        $this->assertSame($privateKeyBefore, $credential->authentication_private_key);
        $this->assertSame('CERTIFICATE_ACCESS', $credential->access_token);
        $this->assertSame(KsefConnectionTestStatus::Success, $credential->last_test_status);
    }

    public function test_replacing_certificate_pair_clears_runtime_state(): void
    {
        $original = KsefCertificateFixtureFactory::rsa();
        $replacement = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($original))
            ->assertSessionDoesntHaveErrors();
        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $certificateBefore = $credential->authentication_certificate;
        $this->fillRuntimeState($credential, 'ORIGINAL');

        $this->put(route('integrations.ksef.update'), $this->certificatePayload($replacement))
            ->assertSessionDoesntHaveErrors();

        $credential->refresh();
        $this->assertNotSame($certificateBefore, $credential->authentication_certificate);
        $this->assertRuntimeStateCleared($credential);
    }

    public function test_switching_methods_preserves_both_credentials_and_clears_runtime_state(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'TEST_TOKEN_TO_PRESERVE',
        ]))->assertSessionDoesntHaveErrors();
        $fixture = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($fixture))
            ->assertSessionDoesntHaveErrors();
        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $this->assertSame('TEST_TOKEN_TO_PRESERVE', $credential->api_token);
        $this->assertNotNull($credential->authentication_certificate);
        $this->assertNotNull($credential->authentication_private_key);
        $this->fillRuntimeState($credential, 'CERTIFICATE');

        $this->put(route('integrations.ksef.update'), $this->payload([
            'authentication_method' => 'token',
        ]))->assertSessionDoesntHaveErrors();

        $credential->refresh();
        $this->assertSame(KsefAuthenticationMethod::Token, $credential->authentication_method);
        $this->assertSame('TEST_TOKEN_TO_PRESERVE', $credential->api_token);
        $this->assertNotNull($credential->authentication_certificate);
        $this->assertNotNull($credential->authentication_private_key);
        $this->assertRuntimeStateCleared($credential);

        $this->fillRuntimeState($credential, 'TOKEN');
        $this->put(route('integrations.ksef.update'), $this->payload([
            'authentication_method' => 'certificate',
        ]))->assertSessionDoesntHaveErrors();
        $this->assertRuntimeStateCleared($credential->refresh());
    }

    public function test_context_nip_change_preserves_certificate_material_and_clears_runtime(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa();
        $this->put(route('integrations.ksef.update'), $this->certificatePayload($fixture))
            ->assertSessionDoesntHaveErrors();
        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $certificateBefore = $credential->authentication_certificate;
        $privateKeyBefore = $credential->authentication_private_key;
        $this->fillRuntimeState($credential, 'CONTEXT');

        $this->put(route('integrations.ksef.update'), $this->payload([
            'authentication_method' => 'certificate',
            'context_nip' => '0987654321',
        ]))->assertSessionDoesntHaveErrors();

        $credential->refresh();
        $this->assertSame($certificateBefore, $credential->authentication_certificate);
        $this->assertSame($privateKeyBefore, $credential->authentication_private_key);
        $this->assertRuntimeStateCleared($credential);
    }

    public function test_passphrase_is_not_flashed_and_saved_certificate_enables_connection_test(): void
    {
        $fixture = KsefCertificateFixtureFactory::rsa(passphrase: 'PASSPHRASE_DO_NOT_FLASH');

        $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->certificatePayload(
                $fixture,
                ['name' => ''],
                'PASSPHRASE_DO_NOT_FLASH',
            ))
            ->assertSessionHasErrors('name');

        $this->assertArrayNotHasKey('authentication_private_key_passphrase', session()->getOldInput());
        $this->assertNull(session()->getOldInput('authentication_private_key_passphrase'));
        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee('PASSPHRASE_DO_NOT_FLASH');

        $this->put(route('integrations.ksef.update'), $this->certificatePayload(
            $fixture,
            passphrase: 'PASSPHRASE_DO_NOT_FLASH',
        ))->assertSessionDoesntHaveErrors();
        Http::preventStrayRequests();

        $html = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSeeText('Test połączenia używa zapisanej konfiguracji.')
            ->getContent();
        preg_match('/<button[^>]+data-ksef-test-button[^>]*>/s', $html, $button);
        $this->assertArrayHasKey(0, $button);
        $this->assertStringNotContainsString('disabled', $button[0]);
        Http::assertNothingSent();
    }

    public function test_certificate_method_without_saved_pair_keeps_connection_test_disabled(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => 'test',
            'context_nip' => '1234567890',
        ])->save();
        KsefCredential::query()->create([
            'environment' => 'test',
            'authentication_method' => KsefAuthenticationMethod::Certificate,
        ]);
        Http::preventStrayRequests();

        $html = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSeeText('Najpierw zapisz certyfikat KSeF i klucz prywatny.')
            ->getContent();
        preg_match('/<button[^>]+data-ksef-test-button[^>]*>/s', $html, $button);
        $this->assertArrayHasKey(0, $button);
        $this->assertStringContainsString('disabled', $button[0]);
        Http::assertNothingSent();
    }

    private function certificatePayload(
        array $fixture,
        array $overrides = [],
        ?string $passphrase = null,
    ): array {
        return $this->payload(array_replace([
            'authentication_method' => 'certificate',
            'authentication_certificate' => $this->upload('certificate.cer', $fixture['certificate']),
            'authentication_private_key' => $this->upload('private-key.pem', $fixture['private_key']),
            'authentication_private_key_passphrase' => $passphrase,
        ], $overrides));
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function payload(array $overrides = []): array
    {
        $payload = array_replace([
            'name' => 'KSeF',
            'environment' => 'test',
            'api_token_environment' => 'test',
            'context_nip' => '1234567890',
            'authentication_method' => 'token',
            'api_token' => '',
            'is_active' => false,
            'automatic_submission' => false,
            'send_without_buyer_nip' => false,
            'include_recipient_data' => false,
            'include_buyer_contact_data' => false,
            'include_additional_information' => false,
            'include_order_reference' => true,
            'include_bank_account' => true,
            'include_gtu' => true,
            'include_seller_vat_prefix' => false,
            'zero_vat_classification' => 'wdt',
            'default_split_payment' => false,
        ], $overrides);

        if (! array_key_exists('api_token_environment', $overrides)) {
            $payload['api_token_environment'] = $payload['environment'];
        }

        return $payload;
    }

    private function fillRuntimeState(KsefCredential $credential, string $prefix): void
    {
        $credential->forceFill([
            'access_token' => $prefix.'_ACCESS',
            'access_token_valid_until' => now()->addMinutes(10),
            'refresh_token' => $prefix.'_REFRESH',
            'refresh_token_valid_until' => now()->addDay(),
            'last_tested_at' => now(),
            'last_test_status' => KsefConnectionTestStatus::Success,
            'last_test_message' => $prefix.' message',
            'last_test_invoice_write' => true,
            'last_system_warning' => $prefix.' warning',
        ])->save();
    }

    private function assertRuntimeStateCleared(KsefCredential $credential): void
    {
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->access_token_valid_until);
        $this->assertNull($credential->refresh_token);
        $this->assertNull($credential->refresh_token_valid_until);
        $this->assertNull($credential->last_tested_at);
        $this->assertNull($credential->last_test_status);
        $this->assertNull($credential->last_test_message);
        $this->assertNull($credential->last_test_invoice_write);
        $this->assertNull($credential->last_system_warning);
    }
}
