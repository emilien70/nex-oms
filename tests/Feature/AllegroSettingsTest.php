<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingClient;
use Modules\Shipments\Models\CourierAccount;
use Tests\TestCase;

class AllegroSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_creates_a_separate_allegro_account_and_credentials_are_encrypted(): void
    {
        $this->get(route('integrations.allegro.edit'))
            ->assertOk()
            ->assertSee('Konfiguracja połączenia')
            ->assertSee('Integracja nie prosi o dostęp do tworzenia ani edytowania ofert.');

        $this->put(route('integrations.allegro.update'), $this->settingsPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $account = AllegroAccount::query()->firstOrFail();
        $this->assertSame('client-secret-value', $account->client_secret);
        $this->assertNotSame('client-secret-value', DB::table('allegro_accounts')->value('client_secret'));

        $payload = $this->settingsPayload();
        $payload['name'] = 'Konto po zmianie';
        $payload['client_secret'] = '';

        $this->put(route('integrations.allegro.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('client-secret-value', $account->fresh()->client_secret);
        $this->assertSame('Konto po zmianie', $account->fresh()->name);
    }

    public function test_device_flow_tests_account_scopes_and_links_existing_allegro_shipping_account(): void
    {
        $shipping = $this->legacyShippingAccount();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/oauth/device')) {
                return Http::response([
                    'user_code' => 'abc123xyz',
                    'device_code' => 'private-device-code',
                    'expires_in' => 3600,
                    'interval' => 5,
                    'verification_uri' => 'https://allegro.pl/skojarz-aplikacje',
                    'verification_uri_complete' => 'https://allegro.pl/skojarz-aplikacje?code=abc123xyz',
                ]);
            }

            if (str_contains($request->url(), '/auth/oauth/token')) {
                return Http::response([
                    'access_token' => 'new-shared-access-token',
                    'refresh_token' => 'new-shared-refresh-token',
                    'expires_in' => 43199,
                    'scope' => implode(' ', AllegroAccount::REQUIRED_SCOPES),
                ]);
            }

            if (str_ends_with($request->url(), '/me')) {
                return Http::response([
                    'id' => '12345678',
                    'login' => 'multi-click',
                    'baseMarketplace' => ['id' => 'allegro-pl'],
                ]);
            }

            if (str_ends_with($request->url(), '/order/event-stats')) {
                return Http::response(['latestEvent' => ['id' => 'event-1']]);
            }

            if (str_ends_with($request->url(), '/shipment-management/delivery-services')) {
                return Http::response(['services' => []]);
            }

            return Http::response([], 404);
        });

        $this->withSession(['allegro_device_authorization' => ['account_id' => $shipping->id]])
            ->post(route('integrations.allegro.device.start'), $this->settingsPayload() + ['_method' => 'PUT'])
            ->assertRedirect()
            ->assertSessionHas('allegro_account_device_authorization.user_code', 'abc123xyz')
            ->assertSessionHas('allegro_device_authorization.account_id', $shipping->id);

        $this->postJson(route('integrations.allegro.device.poll'))
            ->assertOk()
            ->assertJsonPath('status', 'connected');

        $account = AllegroAccount::query()->firstOrFail();
        $this->assertTrue($account->isOperational());
        $this->assertSame('multi-click', $account->allegro_login);
        $this->assertSame('12345678', $account->allegro_user_id);
        $this->assertSame('allegro-pl', $account->marketplace_id);
        $this->assertSame(AllegroAccount::REQUIRED_SCOPES, $account->granted_scopes);
        $this->assertNotSame('new-shared-access-token', DB::table('allegro_accounts')->value('access_token'));
        $this->assertNotSame('new-shared-refresh-token', DB::table('allegro_accounts')->value('refresh_token'));

        $shipping->refresh();
        $this->assertSame($account->id, $shipping->allegro_account_id);
        $this->assertSame('new-shared-access-token', $shipping->resolvedApiToken());
        $this->assertSame('new-shared-refresh-token', $shipping->resolvedApiRefreshToken());

        $oauthLogs = DB::table('integration_api_logs')
            ->where('integration', 'allegro')
            ->get(['request_payload', 'response_payload'])
            ->toJson();
        $this->assertStringNotContainsString('new-shared-access-token', $oauthLogs);
        $this->assertStringNotContainsString('new-shared-refresh-token', $oauthLogs);
        $this->assertStringNotContainsString('private-device-code', $oauthLogs);
    }

    public function test_authorization_without_required_scopes_is_rejected(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/oauth/device')) {
                return Http::response([
                    'user_code' => 'scope123',
                    'device_code' => 'device-code',
                    'expires_in' => 3600,
                    'interval' => 5,
                    'verification_uri' => 'https://allegro.pl/skojarz-aplikacje',
                ]);
            }

            return Http::response([
                'access_token' => 'limited-access-token',
                'refresh_token' => 'limited-refresh-token',
                'expires_in' => 43199,
                'scope' => 'allegro:api:orders:read',
            ]);
        });

        $this->post(route('integrations.allegro.device.start'), $this->settingsPayload())->assertRedirect();

        $this->postJson(route('integrations.allegro.device.poll'))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $account = AllegroAccount::query()->firstOrFail();
        $this->assertFalse($account->is_active);
        $this->assertSame(AllegroAccount::STATUS_ERROR, $account->last_test_status);
        $this->assertStringContainsString('wymaganych uprawnień', (string) $account->last_error);
    }

    public function test_device_authorization_sends_scopes_in_the_url_with_percent_encoded_spaces(): void
    {
        Http::fake(function ($request) {
            $queryString = (string) parse_url($request->url(), PHP_URL_QUERY);
            parse_str($queryString, $query);

            if (($query['scope'] ?? null) !== implode(' ', AllegroAccount::REQUIRED_SCOPES)
                || str_contains($queryString, '+')
                || ! str_contains($queryString, '%20')
                || array_key_exists('scope', $request->data())) {
                return Http::response([
                    'error' => 'invalid_scope',
                    'error_description' => 'OAuth 2.0 Parameter: scope',
                ], 400);
            }

            return Http::response([
                'user_code' => 'scope123',
                'device_code' => 'private-device-code',
                'expires_in' => 3600,
                'interval' => 5,
                'verification_uri' => 'https://allegro.pl/skojarz-aplikacje',
            ]);
        });

        $this->post(route('integrations.allegro.device.start'), $this->settingsPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('allegro_account_device_authorization.user_code', 'scope123');

        Http::assertSentCount(1);
    }

    public function test_changing_application_identity_clears_authorization_and_unlinks_shipping(): void
    {
        $account = $this->connectedAccount();
        $shipping = $this->legacyShippingAccount(['allegro_account_id' => $account->id]);
        $payload = $this->settingsPayload();
        $payload['client_id'] = 'different-client-id';
        $payload['client_secret'] = '';

        $this->put(route('integrations.allegro.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertFalse($account->is_active);
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertNull($account->allegro_login);
        $this->assertNull($shipping->fresh()->allegro_account_id);
        $this->assertSame('legacy-access-token', $shipping->fresh()->resolvedApiToken());
    }

    public function test_disconnect_preserves_existing_courier_fallback_credentials(): void
    {
        $account = $this->connectedAccount();
        $shipping = $this->legacyShippingAccount(['allegro_account_id' => $account->id]);

        $this->delete(route('integrations.allegro.disconnect'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $account->refresh();
        $shipping->refresh();
        $this->assertFalse($account->is_active);
        $this->assertNull($account->access_token);
        $this->assertNull($shipping->allegro_account_id);
        $this->assertSame('legacy-access-token', $shipping->resolvedApiToken());
    }

    public function test_validation_and_oauth_errors_do_not_flash_the_client_secret(): void
    {
        $payload = $this->settingsPayload();
        $payload['name'] = '';

        $this->put(route('integrations.allegro.update'), $payload)
            ->assertSessionHasErrors('name')
            ->assertSessionMissing('_old_input.client_secret');

        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->post(route('integrations.allegro.device.start'), $this->settingsPayload())
            ->assertSessionHasErrors('allegro_device')
            ->assertSessionMissing('_old_input.client_secret');
    }

    public function test_expiring_token_is_refreshed_before_testing_the_account(): void
    {
        $account = $this->connectedAccount();
        $account->update(['access_token_expires_at' => now()->addMinute()]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/oauth/token')) {
                return Http::response([
                    'access_token' => 'refreshed-access-token',
                    'refresh_token' => 'rotated-refresh-token',
                    'expires_in' => 43200,
                ]);
            }

            if (str_ends_with($request->url(), '/me')) {
                return Http::response([
                    'id' => '12345678',
                    'login' => 'multi-click',
                    'baseMarketplace' => ['id' => 'allegro-pl'],
                ]);
            }

            return Http::response([]);
        });

        $this->post(route('integrations.allegro.test-connection'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertTrue($account->isOperational());
        $this->assertSame('refreshed-access-token', $account->access_token);
        $this->assertSame('rotated-refresh-token', $account->refresh_token);
        $this->assertSame(AllegroAccount::REQUIRED_SCOPES, $account->granted_scopes);
        $this->assertTrue($account->access_token_expires_at->greaterThan(now()->addHours(11)));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/me')
            && $request->hasHeader('Authorization', 'Bearer refreshed-access-token'));
        Http::assertSentCount(4);
    }

    public function test_shipping_uses_the_shared_token_without_overwriting_legacy_environment_or_credentials(): void
    {
        $account = $this->connectedAccount();
        $account->update([
            'environment' => 'sandbox',
            'access_token_expires_at' => now()->addMinute(),
        ]);
        $shipping = $this->legacyShippingAccount();
        $accounts = app(AllegroAccountService::class);
        $accounts->markConnected($account, [
            'id' => '12345678',
            'login' => 'multi-click',
            'baseMarketplace' => ['id' => 'allegro-pl'],
        ]);

        Http::fake([
            'https://allegro.pl.allegrosandbox.pl/auth/oauth/token' => Http::response([
                'access_token' => 'shared-refreshed-access-token',
                'refresh_token' => 'shared-rotated-refresh-token',
                'expires_in' => 43200,
            ]),
            'https://api.allegro.pl.allegrosandbox.pl/shipment-management/delivery-services' => Http::response(['services' => []]),
        ]);

        $result = app(AllegroShippingClient::class)->testConnection($shipping->fresh());

        $this->assertSame(['services' => []], $result);
        $this->assertSame('shared-refreshed-access-token', $account->fresh()->access_token);
        $this->assertSame('production', $shipping->fresh()->environment);
        $this->assertSame('legacy-access-token', $shipping->fresh()->api_token);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/delivery-services')
            && $request->hasHeader('Authorization', 'Bearer shared-refreshed-access-token'));
        Http::assertSentCount(2);

        $accounts->disconnect($account);

        $this->assertSame('legacy-access-token', $shipping->fresh()->resolvedApiToken());
        $this->assertSame('https://api.allegro.pl', $shipping->fresh()->baseUrl());
    }

    public function test_cancelling_main_authorization_does_not_cancel_shipping_authorization(): void
    {
        $this->withSession([
            'allegro_account_device_authorization' => ['account_id' => 1],
            'allegro_device_authorization' => ['account_id' => 4],
        ])->post(route('integrations.allegro.device.cancel'), ['_method' => 'PUT'])
            ->assertRedirect()
            ->assertSessionMissing('allegro_account_device_authorization')
            ->assertSessionHas('allegro_device_authorization.account_id', 4);
    }

    public function test_connection_error_blocks_shipping_instead_of_using_legacy_credentials(): void
    {
        $account = $this->connectedAccount();
        $shipping = $this->legacyShippingAccount(['allegro_account_id' => $account->id]);

        app(AllegroAccountService::class)->markConnectionError($account, 'Brak dostepu do konta.');

        $shipping->refresh();
        $this->assertFalse($shipping->hasCompleteCredentials());
        $this->assertFalse($shipping->isOperational());
        $this->assertSame('shared-access-token', $shipping->resolvedApiToken());
        $this->assertSame('legacy-access-token', $shipping->api_token);
    }

    public function test_removing_shared_account_keeps_the_courier_account_and_legacy_credentials(): void
    {
        $account = $this->connectedAccount();
        $shipping = $this->legacyShippingAccount(['allegro_account_id' => $account->id]);

        $account->delete();

        $shipping->refresh();
        $this->assertNull($shipping->allegro_account_id);
        $this->assertSame('legacy-access-token', $shipping->resolvedApiToken());
        $this->assertDatabaseHas('courier_accounts', ['id' => $shipping->id]);
    }

    private function settingsPayload(): array
    {
        return [
            'name' => 'multi-click (Allegro)',
            'environment' => 'production',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret-value',
        ];
    }

    private function connectedAccount(): AllegroAccount
    {
        return AllegroAccount::query()->create([
            'account_key' => AllegroAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click (Allegro)',
            'environment' => 'production',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret-value',
            'access_token' => 'shared-access-token',
            'refresh_token' => 'shared-refresh-token',
            'access_token_expires_at' => now()->addHours(10),
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678',
            'allegro_login' => 'multi-click',
            'marketplace_id' => 'allegro-pl',
            'is_active' => true,
            'last_tested_at' => now(),
            'last_test_status' => AllegroAccount::STATUS_SUCCESS,
        ]);
    }

    private function legacyShippingAccount(array $overrides = []): CourierAccount
    {
        return CourierAccount::query()->create(array_merge([
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            'name' => 'Wysylam z Allegro',
            'environment' => 'production',
            'organization_id' => 'legacy-client-id',
            'api_secret' => 'legacy-client-secret',
            'api_token' => 'legacy-access-token',
            'api_refresh_token' => 'legacy-refresh-token',
            'settings' => [],
            'is_active' => true,
        ], $overrides));
    }
}
