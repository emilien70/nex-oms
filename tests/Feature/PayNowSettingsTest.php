<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Services\PayNowSignatureService;
use Tests\TestCase;

class PayNowSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_settings_page_and_integration_tile_are_available_without_exposing_secrets(): void
    {
        $account = $this->account();

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('data-integration="paynow"', false)
            ->assertSee('href="'.route('integrations.paynow.edit').'"', false)
            ->assertSeeText('PayNow');

        $settings = $this->get(route('integrations.paynow.edit'))
            ->assertOk()
            ->assertSeeText('Konto PayNow')
            ->assertSee('Zapisano - pozostaw puste bez zmian')
            ->assertDontSee((string) $account->api_key)
            ->assertDontSee((string) $account->signature_key);

        $this->assertMatchesRegularExpression('/<input\s+id="paynow-active"[^>]*>/s', $settings->getContent());
        $this->assertDoesNotMatchRegularExpression('/<input\s+id="paynow-active"[^>]*\bdisabled\b/s', $settings->getContent());
    }

    public function test_settings_are_saved_encrypted_and_blank_secret_fields_preserve_credentials(): void
    {
        Http::fake(['https://api.sandbox.paynow.pl/v3/payments/paymentmethods*' => Http::response([])]);

        $this->put(route('integrations.paynow.update'), $this->settingsPayload([
            'api_key' => 'new-api-key',
            'signature_key' => 'new-signature-key',
        ]))->assertRedirect(route('integrations.paynow.edit'));

        $account = PayNowAccount::query()->firstOrFail();

        $this->assertSame('new-api-key', $account->api_key);
        $this->assertSame('new-signature-key', $account->signature_key);
        $this->assertFalse($account->is_active);
        $this->assertSame(PayNowConnectionTestStatus::Success, $account->last_test_status);
        $this->assertNotSame('new-api-key', DB::table('paynow_accounts')->value('api_key'));
        $this->assertNotSame('new-signature-key', DB::table('paynow_accounts')->value('signature_key'));

        $this->put(route('integrations.paynow.update'), $this->settingsPayload([
            'name' => 'PayNow sklep',
            'api_key' => '',
            'signature_key' => '',
        ]))->assertRedirect(route('integrations.paynow.edit'));

        $account->refresh();

        $this->assertSame('PayNow sklep', $account->name);
        $this->assertSame('new-api-key', $account->api_key);
        $this->assertSame('new-signature-key', $account->signature_key);
        Http::assertSentCount(1);
    }

    public function test_changing_environment_requires_new_credentials_and_secrets_are_not_flashed(): void
    {
        $this->account();

        $response = $this->from(route('integrations.paynow.edit'))
            ->put(route('integrations.paynow.update'), $this->settingsPayload([
                'environment' => 'production',
                'api_key' => '',
                'signature_key' => '',
            ]));

        $response->assertRedirect(route('integrations.paynow.edit'))
            ->assertSessionHasErrors(['api_key', 'signature_key']);

        $invalid = $this->from(route('integrations.paynow.edit'))
            ->put(route('integrations.paynow.update'), $this->settingsPayload([
                'name' => '',
                'api_key' => 'must-not-enter-session',
                'signature_key' => 'also-must-not-enter-session',
            ]));

        $invalid->assertSessionHasErrors(['name']);
        $oldInput = session()->get('_old_input', []);

        $this->assertArrayNotHasKey('api_key', $oldInput);
        $this->assertArrayNotHasKey('signature_key', $oldInput);
    }

    public function test_saving_can_switch_from_active_sandbox_to_production_in_one_step(): void
    {
        $account = $this->account(['is_active' => true, 'last_test_status' => PayNowConnectionTestStatus::Success]);
        Http::fake(['https://api.paynow.pl/v3/payments/paymentmethods*' => function () use ($account) {
            $this->assertSame(PayNowEnvironment::Sandbox, $account->fresh()->environment);
            $this->assertSame('secret-api-key', $account->fresh()->api_key);

            return Http::response([['paymentMethods' => [['id' => 1000]]]]);
        }]);

        $this->put(route('integrations.paynow.update'), $this->settingsPayload([
            'environment' => 'production', 'api_key' => 'prod-api-key',
            'signature_key' => 'prod-signature-key', 'is_active' => '1',
        ]))->assertRedirect(route('integrations.paynow.edit'))->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(PayNowEnvironment::Production, $account->environment);
        $this->assertSame('prod-api-key', $account->api_key);
        $this->assertSame('prod-signature-key', $account->signature_key);
        $this->assertTrue($account->is_active);
        $this->assertSame(PayNowConnectionTestStatus::Success, $account->last_test_status);
        $this->assertNotNull($account->last_tested_at);
        Http::assertSent(function (Request $request): bool {
            $signature = app(PayNowSignatureService::class)->generateV3(
                'prod-api-key', 'prod-signature-key', $request->header('Idempotency-Key')[0],
                parameters: ['amount' => 100, 'currency' => 'PLN'],
            );

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.paynow.pl/v3/payments/paymentmethods?')
                && $request->hasHeader('Api-Key', 'prod-api-key')
                && $request->hasHeader('Signature', $signature);
        });
        Http::assertSentCount(1);
        $this->assertDatabaseCount('paynow_payments', 0);
    }

    public function test_failed_automatic_test_keeps_active_settings_and_does_not_flash_keys(): void
    {
        $account = $this->account(['is_active' => true, 'last_test_status' => PayNowConnectionTestStatus::Success]);
        $original = $account->refresh()->getRawOriginal();
        Http::fake(['https://api.paynow.pl/v3/payments/paymentmethods*' => Http::response([
            'errors' => [['errorType' => 'UNAUTHORIZED', 'message' => 'invalid-prod-api-key']],
        ], 401)]);

        $this->from(route('integrations.paynow.edit'))
            ->put(route('integrations.paynow.update'), $this->settingsPayload([
                'environment' => 'production', 'api_key' => 'invalid-prod-api-key',
                'signature_key' => 'invalid-prod-signature-key', 'is_active' => '1',
            ]))
            ->assertRedirect(route('integrations.paynow.edit'))
            ->assertSessionHasErrors('connection_test')
            ->assertSessionHasInput('environment', 'production');

        $this->assertSame($original, $account->fresh()->getRawOriginal());
        $this->assertArrayNotHasKey('api_key', session()->getOldInput());
        $this->assertArrayNotHasKey('signature_key', session()->getOldInput());
        $settings = $this->get(route('integrations.paynow.edit'))
            ->assertSeeText('Nie zapisano ustawień PayNow.')
            ->assertDontSee('invalid-prod-api-key')
            ->assertDontSee('invalid-prod-signature-key');
        $this->assertMatchesRegularExpression('/<button[^>]*data-credentials-configured="1"[^>]*\bdisabled\b/s', $settings->getContent());
        Http::assertSentCount(1);
    }

    public function test_enabling_with_saved_keys_tests_once_and_ordinary_edits_do_not(): void
    {
        $account = $this->account();
        Http::fake(['https://api.sandbox.paynow.pl/v3/payments/paymentmethods*' => Http::response([])]);

        $this->put(route('integrations.paynow.update'), $this->settingsPayload(['is_active' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertTrue($account->fresh()->is_active);
        $this->put(route('integrations.paynow.update'), $this->settingsPayload(['name' => 'Nowa nazwa', 'is_active' => '1']))
            ->assertSessionHasNoErrors();
        $this->put(route('integrations.paynow.update'), $this->settingsPayload(['name' => 'Nowa nazwa', 'is_active' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->is_active);
        Http::assertSentCount(1);
    }

    public function test_concurrent_save_is_not_overwritten_by_an_older_automatic_test(): void
    {
        $account = $this->account();
        Http::fake(['https://api.sandbox.paynow.pl/v3/payments/paymentmethods*' => function () use ($account) {
            $account->fresh()->update(['name' => 'Zmieniono równolegle']);

            return Http::response([]);
        }]);

        $this->from(route('integrations.paynow.edit'))
            ->put(route('integrations.paynow.update'), $this->settingsPayload(['is_active' => '1']))
            ->assertSessionHasErrors('connection_test');

        $account->refresh();
        $this->assertSame('Zmieniono równolegle', $account->name);
        $this->assertFalse($account->is_active);
        $this->assertNull($account->last_test_status);
    }

    public function test_connection_test_signs_a_get_request_and_records_success(): void
    {
        $account = $this->account();

        Http::fake([
            'https://api.sandbox.paynow.pl/v3/payments/paymentmethods*' => Http::response([
                [
                    'type' => 'PBL',
                    'paymentMethods' => [
                        ['id' => 1000, 'name' => 'mTransfer'],
                        ['id' => 1001, 'name' => 'Bank testowy'],
                    ],
                ],
            ]),
        ]);

        $this->post(route('integrations.paynow.test-connection'))
            ->assertRedirect(route('integrations.paynow.edit'))
            ->assertSessionHas('success', 'Połączenie z PayNow działa poprawnie. Dostępne metody płatności: 2.');

        $account->refresh();

        $this->assertSame(PayNowConnectionTestStatus::Success, $account->last_test_status);
        $this->assertNotNull($account->last_tested_at);

        Http::assertSent(function (Request $request) use ($account): bool {
            $idempotencyKey = $request->header('Idempotency-Key')[0] ?? '';
            $expectedSignature = app(PayNowSignatureService::class)->generateV3(
                (string) $account->api_key,
                (string) $account->signature_key,
                $idempotencyKey,
                parameters: ['amount' => 100, 'currency' => 'PLN'],
            );

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.sandbox.paynow.pl/v3/payments/paymentmethods?')
                && $request->hasHeader('Api-Key', (string) $account->api_key)
                && $request->hasHeader('Signature', $expectedSignature)
                && $request->hasHeader('Idempotency-Key')
                && data_get($request->data(), 'amount') === 100
                && data_get($request->data(), 'currency') === 'PLN';
        });
        Http::assertSentCount(1);
    }

    public function test_failed_connection_test_records_only_a_safe_message_and_deactivates_account(): void
    {
        $account = $this->account([
            'is_active' => true,
            'last_test_status' => PayNowConnectionTestStatus::Success,
        ]);

        Http::fake([
            '*' => Http::response([
                'errors' => [[
                    'errorType' => 'UNAUTHORIZED',
                    'message' => 'Leaked value: secret-api-key',
                ]],
            ], 401),
        ]);

        $this->post(route('integrations.paynow.test-connection'))
            ->assertRedirect(route('integrations.paynow.edit'))
            ->assertSessionHas('error', 'PayNow odrzucił dane dostępowe. Sprawdź Klucz API i Klucz podpisu.');

        $account->refresh();

        $this->assertSame(PayNowConnectionTestStatus::Error, $account->last_test_status);
        $this->assertFalse($account->is_active);
        $this->assertStringNotContainsString('secret-api-key', (string) $account->last_test_message);
    }

    public function test_manual_test_cannot_apply_a_result_to_concurrently_replaced_credentials(): void
    {
        $account = $this->account(['is_active' => true, 'last_test_status' => PayNowConnectionTestStatus::Success]);
        Http::fake(['https://api.sandbox.paynow.pl/v3/payments/paymentmethods*' => function () use ($account) {
            $account->fresh()->update(['environment' => PayNowEnvironment::Production, 'api_key' => 'other-api-key']);

            return Http::response([], 401);
        }]);

        $this->from(route('integrations.paynow.edit'))
            ->post(route('integrations.paynow.test-connection'))
            ->assertSessionHasErrors('connection_test');

        $account->refresh();
        $this->assertSame(PayNowEnvironment::Production, $account->environment);
        $this->assertTrue($account->is_active);
        $this->assertSame(PayNowConnectionTestStatus::Success, $account->last_test_status);
    }

    public function test_signature_verification_failure_has_a_specific_safe_message(): void
    {
        $account = $this->account();

        Http::fake([
            '*' => Http::response([
                'statusCode' => 400,
                'errors' => [[
                    'errorType' => 'VERIFICATION_FAILED',
                    'message' => 'Requests signature verification failed',
                ]],
            ], 400),
        ]);

        $this->post(route('integrations.paynow.test-connection'))
            ->assertRedirect(route('integrations.paynow.edit'))
            ->assertSessionHas(
                'error',
                'PayNow nie mógł zweryfikować podpisu żądania. Sprawdź, czy Klucz podpisu odpowiada zapisanemu Kluczowi API i wybranemu środowisku.',
            );

        $account->refresh();

        $this->assertSame(PayNowConnectionTestStatus::Error, $account->last_test_status);
        $this->assertFalse($account->is_active);
    }

    public function test_connection_test_without_credentials_does_not_use_the_network(): void
    {
        Http::fake();

        $this->post(route('integrations.paynow.test-connection'))
            ->assertRedirect(route('integrations.paynow.edit'))
            ->assertSessionHas('error', 'Najpierw zapisz Klucz API i Klucz podpisu PayNow.');

        Http::assertNothingSent();
    }

    private function account(array $overrides = []): PayNowAccount
    {
        return PayNowAccount::query()->create(array_merge([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'PayNow',
            'environment' => PayNowEnvironment::Sandbox,
            'api_key' => 'secret-api-key',
            'signature_key' => 'secret-signature-key',
            'is_active' => false,
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ], $overrides));
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'PayNow',
            'environment' => 'sandbox',
            'api_key' => '',
            'signature_key' => '',
            'is_active' => '0',
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
            'continue_url' => 'https://example.test/payment-complete',
        ], $overrides);
    }
}
