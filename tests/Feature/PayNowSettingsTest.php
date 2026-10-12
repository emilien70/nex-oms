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

    public function test_settings_page_and_integration_tile_are_available_without_exposing_secrets(): void
    {
        $account = $this->account();

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('data-integration="paynow"', false)
            ->assertSee('href="'.route('integrations.paynow.edit').'"', false)
            ->assertSeeText('PayNow');

        $this->get(route('integrations.paynow.edit'))
            ->assertOk()
            ->assertSeeText('Konto PayNow')
            ->assertSee('Zapisano - pozostaw puste bez zmian')
            ->assertDontSee((string) $account->api_key)
            ->assertDontSee((string) $account->signature_key);
    }

    public function test_settings_are_saved_encrypted_and_blank_secret_fields_preserve_credentials(): void
    {
        $this->put(route('integrations.paynow.update'), $this->settingsPayload([
            'api_key' => 'new-api-key',
            'signature_key' => 'new-signature-key',
        ]))->assertRedirect(route('integrations.paynow.edit'));

        $account = PayNowAccount::query()->firstOrFail();

        $this->assertSame('new-api-key', $account->api_key);
        $this->assertSame('new-signature-key', $account->signature_key);
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

    public function test_activation_requires_a_successful_test_for_current_credentials(): void
    {
        $account = $this->account();

        $this->from(route('integrations.paynow.edit'))
            ->put(route('integrations.paynow.update'), $this->settingsPayload(['is_active' => '1']))
            ->assertSessionHasErrors(['is_active']);

        $this->assertFalse($account->fresh()->is_active);

        $account->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => PayNowConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ])->save();

        $this->put(route('integrations.paynow.update'), $this->settingsPayload(['is_active' => '1']))
            ->assertRedirect(route('integrations.paynow.edit'));

        $this->assertTrue($account->fresh()->is_active);
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
