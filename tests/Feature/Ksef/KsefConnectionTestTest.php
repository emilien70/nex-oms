<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KsefApiFake;
use Tests\TestCase;

class KsefConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_succeeds_with_invoice_write_even_when_integration_is_inactive(): void
    {
        $credential = $this->configuredCredential(isActive: false);
        $fake = $this->fakeApi();

        $this->post(route('integrations.ksef.test-connection'))
            ->assertRedirect(route('integrations.ksef.edit'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Success, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('Połączenie z KSeF działa poprawnie.', $credential->last_test_message);
        $this->assertNotNull($credential->last_tested_at);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
        $this->assertSame(1, $fake->redeemCalls);
        $this->assertSame(0, $fake->tokenQueryCalls);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/permissions/query/personal/grants')
            && $request->hasHeader('Authorization', 'Bearer '.KsefApiFake::ACCESS_TOKEN)
            && $request->data() === [
                'permissionTypes' => ['InvoiceWrite'],
                'permissionState' => 'Active',
            ]
            && str_contains($request->url(), 'pageOffset=0')
            && str_contains($request->url(), 'pageSize=100'));
    }

    public function test_missing_api_token_records_error_without_http(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Demo,
            'context_nip' => '1234567890',
        ])->save();
        Http::preventStrayRequests();

        $this->post(route('integrations.ksef.test-connection'))
            ->assertRedirect(route('integrations.ksef.edit'));

        Http::assertNothingSent();
        $credential = KsefCredential::query()->where('environment', 'demo')->firstOrFail();
        $this->assertSame(KsefConnectionTestStatus::Error, $credential->last_test_status);
        $this->assertNull($credential->last_test_invoice_write);
        $this->assertNull($credential->last_system_warning);
        $this->assertSame(
            'Najpierw zapisz Token KSeF dla wybranego środowiska.',
            $credential->last_test_message,
        );
    }

    public function test_implicit_nip_owner_with_invoice_write_succeeds_and_uses_safe_token_query(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Success, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('Połączenie z KSeF działa poprawnie.', $credential->last_test_message);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
        $this->assertSame(1, $fake->tokenQueryCalls);

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSee('"invoice_write":true', false)
            ->assertDontSee(KsefApiFake::API_TOKEN);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/tokens')) {
                return false;
            }

            $requestData = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer '.KsefApiFake::ACCESS_TOKEN)
                && str_contains($request->url(), 'status=Active')
                && str_contains($request->url(), 'pageSize=100')
                && ! str_contains($requestData, KsefApiFake::API_TOKEN)
                && ! str_contains($requestData, KsefApiFake::REFRESH_TOKEN)
                && ! str_contains($requestData, 'encryptedToken');
        });
    }

    public function test_implicit_owner_system_warning_keeps_invoice_write_true_and_redacts_secrets(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $fake->warnings['/tokens'] = 'metadata '.KsefApiFake::ACCESS_TOKEN.' '.KsefApiFake::REFRESH_TOKEN.' code=ABC';
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('metadata [ukryto] [ukryto] code=ABC', $credential->last_system_warning);
        $this->assertStringNotContainsString(KsefApiFake::ACCESS_TOKEN, $credential->last_test_message);
        $this->assertStringNotContainsString(KsefApiFake::REFRESH_TOKEN, $credential->last_test_message);

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee(KsefApiFake::API_TOKEN)
            ->assertDontSee(KsefApiFake::ACCESS_TOKEN)
            ->assertDontSee(KsefApiFake::REFRESH_TOKEN);
    }

    public function test_implicit_owner_token_without_invoice_write_is_a_warning(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $fake->tokens[0]['requestedPermissions'] = ['InvoiceRead', 'Introspection'];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertFalse($credential->last_test_invoice_write);
        $this->assertStringContainsString('Token KSeF nie posiada uprawnienia InvoiceWrite', $credential->last_test_message);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
    }

    public function test_non_owner_token_with_invoice_write_and_empty_grants_is_a_warning(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $fake->tokens[0]['authorIdentifier']['value'] = '1111111111';
        $fake->tokens[0]['contextIdentifier']['value'] = '2222222222';
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertFalse($credential->last_test_invoice_write);
        $this->assertStringContainsString('Token posiada InvoiceWrite', $credential->last_test_message);
        $this->assertStringContainsString('w bieżącym kontekście', $credential->last_test_message);
    }

    #[DataProvider('unresolvedCurrentTokenResponses')]
    public function test_ambiguous_or_malformed_current_token_is_reported_as_unknown(
        array $tokens,
        ?string $continuationToken,
    ): void {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $fake->tokens = $tokens;
        $fake->tokenContinuationToken = $continuationToken;
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertNull($credential->last_test_invoice_write);
        $this->assertStringContainsString('nie udało się jednoznacznie potwierdzić uprawnienia InvoiceWrite', $credential->last_test_message);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
    }

    public static function unresolvedCurrentTokenResponses(): array
    {
        $ownerToken = self::ownerTokenMetadata();

        return [
            'multiple tokens' => [[$ownerToken, $ownerToken], null],
            'continuation token' => [[$ownerToken], 'NEXT_PAGE'],
            'zero tokens' => [[], null],
            'malformed requested permissions' => [[array_replace(
                $ownerToken,
                ['requestedPermissions' => 'InvoiceWrite'],
            )], null],
            'inactive metadata' => [[array_replace($ownerToken, ['status' => 'Blocked'])], null],
        ];
    }

    public function test_current_token_lookup_failure_is_warning_unknown_and_keeps_runtime_tokens(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $fake->failures['/tokens'] = [
            'status' => 500,
            'headers' => ['X-System-Warning' => 'token diagnostic '.KsefApiFake::ACCESS_TOKEN],
        ];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertNull($credential->last_test_invoice_write);
        $this->assertStringContainsString('nie udało się jednoznacznie potwierdzić uprawnienia InvoiceWrite', $credential->last_test_message);
        $this->assertSame('token diagnostic [ukryto]', $credential->last_system_warning);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
    }

    public function test_pesel_author_is_not_inferred_as_owner(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->permissions = [];
        $fake->tokens[0]['authorIdentifier'] = [
            'type' => 'Pesel',
            'value' => '90010112345',
        ];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertFalse($credential->last_test_invoice_write);
        $this->assertStringContainsString('nie wykryto aktywnego uprawnienia InvoiceWrite', $credential->last_test_message);
    }

    public function test_system_warning_changes_success_to_warning_and_is_sanitized_in_db_and_html(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->warnings['/auth/AUTH-REFERENCE'] = '[TEST001]: '.KsefApiFake::AUTHENTICATION_TOKEN;
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Warning, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('[TEST001]: [ukryto]', $credential->last_system_warning);
        $this->assertStringNotContainsString(KsefApiFake::AUTHENTICATION_TOKEN, $credential->last_test_message);

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSeeText('[TEST001]: [ukryto]')
            ->assertDontSee(KsefApiFake::API_TOKEN)
            ->assertDontSee(KsefApiFake::AUTHENTICATION_TOKEN)
            ->assertDontSee(KsefApiFake::ACCESS_TOKEN)
            ->assertDontSee(KsefApiFake::REFRESH_TOKEN);
    }

    public function test_encrypted_token_echoed_in_system_warning_is_redacted_before_diagnostics_are_stored(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->echoEncryptedTokenInWarning = true;
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $this->assertNotNull($fake->lastEncryptedToken);
        $credential->refresh();
        $this->assertSame('diagnostic [ukryto] code=ABC', $credential->last_system_warning);
        $this->assertStringNotContainsString($fake->lastEncryptedToken, $credential->last_test_message);
        $this->assertStringNotContainsString($fake->lastEncryptedToken, $credential->last_system_warning);

        $storedCredential = json_encode(
            DB::table('ksef_credentials')->where('id', $credential->getKey())->first(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString($fake->lastEncryptedToken, $storedCredential);

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSeeText('diagnostic [ukryto] code=ABC')
            ->assertDontSee($fake->lastEncryptedToken);
    }

    public function test_permission_endpoint_failure_records_error_but_keeps_new_runtime_tokens(): void
    {
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->failures['/permissions/query/personal/grants'] = ['status' => 500];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Error, $credential->last_test_status);
        $this->assertNull($credential->last_test_invoice_write);
        $this->assertSame(KsefApiFake::ACCESS_TOKEN, $credential->access_token);
        $this->assertSame(KsefApiFake::REFRESH_TOKEN, $credential->refresh_token);
        $this->assertStringContainsString('nie udało się sprawdzić uprawnień', $credential->last_test_message);
    }

    public function test_rate_limit_records_retry_after_without_retry_and_preserves_previous_runtime_tokens(): void
    {
        $credential = $this->configuredCredential();
        $credential->forceFill([
            'access_token' => 'PREVIOUS_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addMinutes(10),
            'refresh_token' => 'PREVIOUS_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();
        $fake = new KsefApiFake;
        $fake->failures['/auth/challenge'] = [
            'status' => 429,
            'headers' => ['Retry-After' => '30'],
        ];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        Http::assertSentCount(1);
        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Error, $credential->last_test_status);
        $this->assertStringContainsString('30 s', $credential->last_test_message);
        $this->assertSame('PREVIOUS_ACCESS_TOKEN', $credential->access_token);
        $this->assertSame('PREVIOUS_REFRESH_TOKEN', $credential->refresh_token);
    }

    public function test_unambiguous_credential_failure_clears_previous_runtime_tokens(): void
    {
        $credential = $this->configuredCredential();
        $credential->forceFill([
            'access_token' => 'PREVIOUS_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addMinutes(10),
            'refresh_token' => 'PREVIOUS_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ])->save();
        $fake = new KsefApiFake;
        $fake->statusCodes = [450];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Error, $credential->last_test_status);
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->access_token_valid_until);
        $this->assertNull($credential->refresh_token);
        $this->assertNull($credential->refresh_token_valid_until);
        $this->assertSame(0, $fake->redeemCalls);
    }

    public function test_polling_timeout_records_safe_error_without_redeem(): void
    {
        config()->set('ksef.auth_poll_max_attempts', 3);
        $credential = $this->configuredCredential();
        $fake = new KsefApiFake;
        $fake->statusCodes = [100, 100, 100];
        $this->fakeApi($fake);

        $this->post(route('integrations.ksef.test-connection'));

        $credential->refresh();
        $this->assertSame(KsefConnectionTestStatus::Error, $credential->last_test_status);
        $this->assertStringContainsString('oczekiwanym czasie', $credential->last_test_message);
        $this->assertSame(3, $fake->statusCalls);
        $this->assertSame(0, $fake->redeemCalls);
        $this->assertNull($credential->access_token);
        $this->assertNull($credential->refresh_token);
    }

    public function test_connection_test_form_is_separate_and_secret_free(): void
    {
        $this->configuredCredential();

        $html = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSee('form="ksef-test-connection-form"', false)
            ->assertSee('data-ksef-test-button', false)
            ->getContent();

        preg_match('/<form[^>]+id="ksef-test-connection-form"[^>]*>(.*?)<\/form>/s', $html, $match);
        $this->assertArrayHasKey(1, $match);
        $testForm = $match[1];
        $this->assertStringContainsString('name="_token"', $testForm);
        $this->assertStringNotContainsString('name="api_token"', $testForm);
        $this->assertStringNotContainsString('name="access_token"', $testForm);
        $this->assertStringNotContainsString('name="refresh_token"', $testForm);
        $this->assertStringNotContainsString('name="context_nip"', $testForm);
        $this->assertStringNotContainsString('name="environment"', $testForm);
    }

    private function configuredCredential(bool $isActive = false): KsefCredential
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
            'is_active' => $isActive,
        ])->save();

        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => KsefApiFake::API_TOKEN,
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

    private static function ownerTokenMetadata(): array
    {
        return [
            'referenceNumber' => 'TEST-REFERENCE',
            'authorIdentifier' => [
                'type' => 'Nip',
                'value' => '1234567890',
            ],
            'contextIdentifier' => [
                'type' => 'Nip',
                'value' => '1234567890',
            ],
            'requestedPermissions' => ['InvoiceWrite', 'InvoiceRead', 'Introspection'],
            'status' => 'Active',
            'statusDetails' => [],
        ];
    }
}
