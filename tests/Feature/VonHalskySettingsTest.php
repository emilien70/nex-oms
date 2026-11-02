<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\VonHalsky\Enums\VonHalskyEnvironment;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VonHalskySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
    }

    public function test_settings_are_available_without_creating_an_account_or_calling_api(): void
    {
        $this->get(route('integrations.index'))->assertOk()->assertSee('InPost Von Halsky');
        $this->get(route('integrations.von-halsky.edit'))->assertOk()
            ->assertSee('Konfiguracja połączenia')->assertSee('Stage (testowe)')->assertSee('Nie połączono')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertOk()->assertSee('Podgląd zamówienia');
        $this->assertDatabaseCount('von_halsky_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_hidden_and_never_flashed(): void
    {
        $this->put(route('integrations.von-halsky.update'), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $account = VonHalskyAccount::query()->sole();
        $this->assertSame('TEST_CLIENT_SECRET', $account->client_secret);
        $this->assertNotSame($account->client_secret, DB::table('von_halsky_accounts')->value('client_secret'));
        $this->assertArrayNotHasKey('client_secret', $account->toArray());
        $this->assertArrayNotHasKey('access_token', $account->toArray());
        $this->assertFalse($account->is_active);
        $this->get(route('integrations.von-halsky.edit'))->assertDontSee('TEST_CLIENT_SECRET');

        $this->from(route('integrations.von-halsky.edit'))->put(route('integrations.von-halsky.update'), [
            ...$this->payload(), 'name' => '', 'client_secret' => 'DO_NOT_FLASH_THIS_SECRET',
        ])->assertSessionHasErrors('name')->assertSessionMissing('_old_input.client_secret');
        $this->assertSame('TEST_CLIENT_SECRET', $account->fresh()->client_secret);
        Http::assertNothingSent();
    }

    public function test_first_save_requires_secret_and_rejects_invalid_fields(): void
    {
        $this->put(route('integrations.von-halsky.update'), [...$this->payload(), 'client_secret' => ''])
            ->assertSessionHasErrors('client_secret');
        $this->assertDatabaseCount('von_halsky_accounts', 0);
        $this->put(route('integrations.von-halsky.update'), [
            ...$this->payload(), 'environment' => 'https://evil.invalid', 'organization_id' => '12345', 'client_id' => ['bad'],
        ])->assertSessionHasErrors(['environment', 'organization_id', 'client_id']);
        Http::assertNothingSent();
    }

    public function test_renaming_and_empty_secret_preserve_connection_but_connection_changes_clear_it(): void
    {
        $service = app(VonHalskyAccountService::class);
        $account = $service->update($this->payload());
        $account->forceFill([
            'is_active' => true, 'access_token' => 'TEST_TOKEN', 'token_expires_at' => now()->addMinutes(5),
            'last_test_status' => 'success', 'last_tested_at' => now(),
        ])->save();
        $renamed = $service->update([...$this->payload(), 'name' => 'multi-click', 'client_secret' => '', 'expected_lock_version' => 1]);
        $this->assertTrue($renamed->is_active);
        $this->assertSame('TEST_TOKEN', $renamed->access_token);
        $this->assertSame($account->connection_revision, $renamed->connection_revision);
        $this->assertSame('TEST_CLIENT_SECRET', $renamed->client_secret);

        $changed = $service->update([...$this->payload(), 'environment' => 'production', 'client_secret' => '', 'expected_lock_version' => 2]);
        $this->assertSame(VonHalskyEnvironment::Production, $changed->environment);
        $this->assertFalse($changed->is_active);
        $this->assertNull($changed->access_token);
        $this->assertNull($changed->token_expires_at);
        $this->assertNull($changed->last_tested_at);
        $this->assertGreaterThan($renamed->connection_revision, $changed->connection_revision);
        Http::assertNothingSent();
    }

    public function test_stale_settings_and_test_requests_are_rejected_before_network(): void
    {
        $this->put(route('integrations.von-halsky.update'), $this->payload())->assertSessionHasNoErrors();
        $this->put(route('integrations.von-halsky.update'), $this->payload())->assertSessionHasErrors('expected_lock_version');
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => 0])
            ->assertSessionHasErrors('expected_lock_version');
        $this->delete(route('integrations.von-halsky.disconnect'), ['expected_lock_version' => 0])
            ->assertSessionHasErrors('expected_lock_version');
        Http::assertNothingSent();
    }

    #[DataProvider('environments')]
    public function test_connection_uses_merchant_credentials_and_only_reads_one_order(string $environment, string $api, string $auth): void
    {
        $account = app(VonHalskyAccountService::class)->update([...$this->payload(), 'environment' => $environment]);
        Http::fake([
            $auth => Http::response(['access_token' => 'TOKEN_PRIVATE', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'openid api:orders:read']),
            $api.'/*' => Http::response(['page' => ['limit' => 1, 'offset' => 0, 'total' => 0], 'data' => []]),
        ]);
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => $account->lock_version])
            ->assertRedirect()->assertSessionHas('success');
        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertSame('success', $account->last_test_status);
        $this->assertNotSame('TOKEN_PRIVATE', DB::table('von_halsky_accounts')->value('access_token'));
        $this->get(route('integrations.von-halsky.edit'))->assertDontSee('TOKEN_PRIVATE')->assertDontSee('TEST_CLIENT_SECRET');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request->url() === $auth
            && $request['grant_type'] === 'client_credentials' && $request['scope'] === 'openid api:orders:read'
            && $request['client_secret'] === 'TEST_CLIENT_SECRET');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === $api.'/v1/organizations/'.$account->organization_id.'/orders?limit=1&offset=0'
            && $request->hasHeader('Authorization', 'Bearer TOKEN_PRIVATE'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('integration_api_logs', 0);
    }

    public static function environments(): array
    {
        return [
            ['stage', 'https://stage-api.inpost-group.com/inpsa', 'https://stage-account.inpost-group.com/oauth2/token'],
            ['production', 'https://api.inpost-group.com/inpsa', 'https://account.inpost-group.com/oauth2/token'],
        ];
    }

    #[DataProvider('httpErrors')]
    public function test_api_errors_are_safe_and_connection_remains_inactive(int $status, string $message): void
    {
        $account = app(VonHalskyAccountService::class)->update($this->payload());
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'TOKEN_PRIVATE', 'token_type' => 'Bearer', 'expires_in' => 300]),
            '*inpsa/*' => Http::response(['errorMessage' => 'SECRET_AND_CUSTOMER_DATA'], $status, ['Retry-After' => '120', 'Location' => 'https://evil.invalid']),
        ]);
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => $account->lock_version])
            ->assertRedirect()->assertSessionHas('error', fn ($value): bool => str_contains($value, $message)
                && ! str_contains($value, 'SECRET_AND_CUSTOMER_DATA'));
        $account->refresh();
        $this->assertFalse($account->is_active);
        $this->assertSame('error', $account->last_test_status);
        $this->assertNull($account->access_token);
        $this->assertStringNotContainsString('SECRET_AND_CUSTOMER_DATA', $account->toJson());
        $this->assertDatabaseCount('integration_api_logs', 0);
    }

    public static function httpErrors(): array
    {
        return [[400, 'odrzucił żądanie'], [401, 'odrzucił autoryzację'], [403, 'Brak dostępu'], [404, 'Nie znaleziono'],
            [429, 'Limit zapytań'], [500, 'niedostępna'], [302, 'nieoczekiwaną odpowiedź']];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_do_not_activate_the_account(array $token): void
    {
        $account = app(VonHalskyAccountService::class)->update($this->payload());
        Http::fake(['*' => Http::response($token)]);
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => $account->lock_version])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertFalse($account->fresh()->is_active);
        $this->assertNull($account->fresh()->access_token);
        Http::assertSentCount(1);
    }

    public static function invalidTokens(): array
    {
        $valid = ['access_token' => 'TEST_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 300];

        return [
            [[]], [[...$valid, 'token_type' => ['Bearer']]], [[...$valid, 'expires_in' => '300']],
            [[...$valid, 'expires_in' => 0]], [[...$valid, 'scope' => 'api:offers:read']],
            [[...$valid, 'access_token' => "TEST\nINJECTION"]],
        ];
    }

    public function test_test_rejects_a_login_page_instead_of_an_order_list(): void
    {
        $account = app(VonHalskyAccountService::class)->update($this->payload());
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'TEST_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 300]),
            '*inpsa/*' => Http::response('<html>login</html>', 200),
        ]);
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => $account->lock_version])
            ->assertSessionHas('error');
        $this->assertFalse($account->fresh()->is_active);
    }

    #[DataProvider('invalidLists')]
    public function test_malformed_or_cross_organization_list_does_not_confirm_access(array $list): void
    {
        $account = app(VonHalskyAccountService::class)->update($this->payload());
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'TEST_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 300]),
            '*inpsa/*' => Http::response($list),
        ]);
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => $account->lock_version])
            ->assertSessionHas('error');
        $this->assertFalse($account->fresh()->is_active);
    }

    public static function invalidLists(): array
    {
        $page = ['limit' => 1, 'offset' => 0, 'total' => 1];

        return [
            [['data' => [], 'page' => []]],
            [['data' => [['id' => 'TEST', 'organizationId' => '497f6eca-6276-4993-bfeb-53cbbbba6f08']], 'page' => $page]],
            [['data' => [['id' => ['INVALID']]], 'page' => $page]],
        ];
    }

    public function test_configuration_change_while_authorizing_cannot_restore_old_token_or_success(): void
    {
        $service = app(VonHalskyAccountService::class);
        $account = $service->update($this->payload());
        Http::fake(function () use ($service) {
            $current = $service->get();
            $service->update([...$this->payload(), 'environment' => 'production', 'expected_lock_version' => $current->lock_version]);

            return Http::response(['access_token' => 'OLD_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 300]);
        });
        $this->post(route('integrations.von-halsky.test-connection'), ['expected_lock_version' => $account->lock_version])
            ->assertSessionHas('error', fn ($message): bool => str_contains($message, 'zmieniona'));
        $account->refresh();
        $this->assertSame(VonHalskyEnvironment::Production, $account->environment);
        $this->assertNull($account->access_token);
        $this->assertNull($account->last_test_status);
        Http::assertSentCount(1);
    }

    public function test_disconnect_is_local_and_preserves_credentials(): void
    {
        $account = app(VonHalskyAccountService::class)->update($this->payload());
        $account->forceFill(['is_active' => true, 'access_token' => 'TEST_TOKEN', 'last_test_status' => 'success'])->save();
        $this->delete(route('integrations.von-halsky.disconnect'), ['expected_lock_version' => $account->lock_version])
            ->assertRedirect()->assertSessionHas('success');
        $account->refresh();
        $this->assertFalse($account->is_active);
        $this->assertNull($account->access_token);
        $this->assertSame('TEST_CLIENT_SECRET', $account->client_secret);
        Http::assertNothingSent();
    }

    public function test_migration_is_additive_and_down_protects_saved_configuration(): void
    {
        $migration = require database_path('migrations/2026_10_29_010000_create_von_halsky_accounts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('von_halsky_accounts'));
        $migration->up();
        app(VonHalskyAccountService::class)->update($this->payload());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function payload(): array
    {
        return ['name' => 'multi-click', 'environment' => 'stage', 'organization_id' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67',
            'client_id' => 'TEST_CLIENT', 'client_secret' => 'TEST_CLIENT_SECRET', 'expected_lock_version' => 0];
    }
}
