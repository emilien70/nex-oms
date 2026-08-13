<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\Support\KsefApiFake;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefCertificateConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_certificate_connection_succeeds_with_explicit_invoice_write(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec(), isActive: false);
        $fake = $this->fakeApi();

        $this->post(route('integrations.ksef.test-connection'))
            ->assertRedirect(route('integrations.ksef.edit'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Success, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('Połączenie z KSeF działa poprawnie.', $credential->last_test_message);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
        $this->assertSame(1, $fake->xadesInitCalls);
        $this->assertSame(0, $fake->tokenInitCalls);
        $this->assertSame(0, $fake->publicKeyCalls);
        $this->assertSame(0, $fake->tokenQueryCalls);

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSeeText('Certyfikat KSeF')
            ->assertSee('"status":"success"', false)
            ->assertSee('"invoice_write":true', false)
            ->assertDontSee(KsefApiFake::AUTHENTICATION_TOKEN)
            ->assertDontSee(KsefApiFake::ACCESS_TOKEN)
            ->assertDontSee(KsefApiFake::REFRESH_TOKEN);
    }

    public function test_strict_certificate_nip_owner_is_ready_without_tokens_endpoint(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec(
            subjectSerialNumber: 'TINPL-1234567890',
        ));
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Success, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame(0, $fake->tokenQueryCalls);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/tokens'));
    }

    public function test_unknown_certificate_owner_is_warning_without_guessing_or_token_query(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec(
            subjectSerialNumber: 'PESEL-90010112345',
        ));
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertNull($credential->last_test_invoice_write);
        $this->assertStringContainsString('nie udało się jednoznacznie potwierdzić', $credential->last_test_message);
        $this->assertSame(0, $fake->tokenQueryCalls);
    }

    public function test_different_certificate_nip_is_not_inferred_as_owner(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec(
            subjectSerialNumber: 'NIP-0987654321',
        ));
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertFalse($credential->last_test_invoice_write);
        $this->assertSame(0, $fake->tokenQueryCalls);
    }

    public function test_missing_certificate_pair_records_error_without_http(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
        ])->save();
        $credential = KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Certificate,
        ]);
        Http::preventStrayRequests();

        $this->post(route('integrations.ksef.test-connection'));

        Http::assertNothingSent();
        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Error, $credential->last_test_status);
        $this->assertStringContainsString('certyfikat KSeF i klucz prywatny', $credential->last_test_message);
    }

    public function test_certificate_system_warning_is_sanitized_and_keeps_invoice_write_true(): void
    {
        $credential = $this->credential(KsefCertificateFixtureFactory::ec());
        $fake = new KsefApiFake;
        $fake->warnings['/auth/AUTH-REFERENCE'] = 'auth '.KsefApiFake::AUTHENTICATION_TOKEN.' code=ABC';
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('auth [ukryto] code=ABC', $credential->last_system_warning);
        $this->assertStringNotContainsString(KsefApiFake::AUTHENTICATION_TOKEN, $credential->last_test_message);
        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee(KsefApiFake::AUTHENTICATION_TOKEN)
            ->assertDontSee(KsefApiFake::ACCESS_TOKEN)
            ->assertDontSee(KsefApiFake::REFRESH_TOKEN);
    }

    private function credential(array $fixture, bool $isActive = true): KsefCredential
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
            'is_active' => $isActive,
        ])->save();

        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Certificate,
            'authentication_certificate' => $fixture['certificate'],
            'authentication_private_key' => $fixture['private_key'],
        ]);
    }

    private function fakeApi(?KsefApiFake $fake = null): KsefApiFake
    {
        config()->set('ksef.auth_poll_interval_ms', 0);
        $fake ??= new KsefApiFake;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }
}
