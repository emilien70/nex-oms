<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSetting;
use Tests\TestCase;

class KsefCredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_binds_token_context_to_environment_and_exposes_safe_switch_contract(): void
    {
        $response = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertSee('data-ksef-api-token', false)
            ->assertSee('data-ksef-token-environment', false)
            ->assertSee("tokenInput.value = '';", false)
            ->assertSee('tokenEnvironmentInput.value = environmentSelect.value;', false)
            ->assertSee("environmentSelect.addEventListener('change', changeEnvironment);", false);

        $this->assertTokenEnvironmentInput($response->getContent(), 'test');

        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'demo',
        ]))->assertSessionDoesntHaveErrors();

        $response = $this->get(route('integrations.ksef.edit'))
            ->assertOk();

        $this->assertTokenEnvironmentInput($response->getContent(), 'demo');
    }

    public function test_token_is_encrypted_hidden_and_never_rendered_in_html(): void
    {
        $token = 'SUPER_SECRET_KSEF_TOKEN';

        Http::preventStrayRequests();
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => $token,
        ]))->assertSessionDoesntHaveErrors();

        $credential = KsefCredential::query()->firstOrFail();

        $this->assertSame($token, $credential->api_token);
        $this->assertNotSame($token, DB::table('ksef_credentials')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $credential->toArray());

        $response = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee($token)
            ->assertSeeText('Token skonfigurowany dla wybranego środowiska.');

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]+id="ksef-api-token"[^>]+value=/i',
            $response->getContent(),
        );
    }

    public function test_blank_token_preserves_existing_environment_credential(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'TOKEN_TEST',
        ]));

        $this->put(route('integrations.ksef.update'), $this->payload([
            'name' => 'Zmieniona nazwa',
            'api_token' => '',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame(
            'TOKEN_TEST',
            KsefCredential::query()->where('environment', 'test')->firstOrFail()->api_token,
        );
        $this->assertSame(1, KsefSetting::query()->count());
    }

    public function test_non_empty_token_with_mismatched_environment_is_rejected_without_side_effects(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'name' => 'Konfiguracja bazowa',
            'api_token' => 'TOKEN_TEST',
        ]))->assertSessionDoesntHaveErrors();

        $settingsBefore = KsefSetting::query()->firstOrFail()->getAttributes();

        $response = $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->payload([
                'name' => 'Ta nazwa nie może zostać zapisana',
                'environment' => 'production',
                'api_token_environment' => 'test',
                'api_token' => 'TOKEN_TEST_SHOULD_NOT_REACH_PRODUCTION',
            ]));

        $response
            ->assertRedirect(route('integrations.ksef.edit'))
            ->assertSessionHasErrors('api_token_environment');

        $this->assertSame($settingsBefore, KsefSetting::query()->firstOrFail()->getAttributes());
        $this->assertDatabaseMissing('ksef_credentials', ['environment' => 'production']);
        $this->assertSame('TOKEN_TEST', $this->tokenFor('test'));
        $this->assertArrayNotHasKey('api_token', session()->getOldInput());
        $this->assertNull(session()->getOldInput('api_token'));

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee('TOKEN_TEST_SHOULD_NOT_REACH_PRODUCTION');
    }

    public function test_matching_token_environment_is_accepted_and_encrypted(): void
    {
        $token = 'TOKEN_DEMO';

        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'demo',
            'api_token_environment' => 'demo',
            'api_token' => $token,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame($token, $this->tokenFor('demo'));
        $this->assertNotSame(
            $token,
            DB::table('ksef_credentials')->where('environment', 'demo')->value('api_token'),
        );
    }

    public function test_blank_token_allows_environment_switch_and_preserves_other_credentials(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'TOKEN_TEST',
        ]))->assertSessionDoesntHaveErrors();

        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'production',
            'api_token_environment' => 'test',
            'api_token' => '',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame('production', KsefSetting::query()->firstOrFail()->environment->value);
        $this->assertSame('TOKEN_TEST', $this->tokenFor('test'));
        $this->assertNull(
            KsefCredential::query()->where('environment', 'production')->firstOrFail()->api_token,
        );
    }

    public function test_tokens_are_preserved_independently_per_environment(): void
    {
        Http::preventStrayRequests();

        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'test',
            'api_token' => 'TOKEN_TEST',
        ]));
        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'demo',
            'api_token' => 'TOKEN_DEMO',
        ]));
        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'production',
            'api_token' => 'TOKEN_PRODUCTION',
        ]));
        $this->put(route('integrations.ksef.update'), $this->payload([
            'environment' => 'test',
            'api_token' => '',
        ]));

        $this->assertSame(1, KsefSetting::query()->count());
        $this->assertSame(3, KsefCredential::query()->count());
        $this->assertSame('TOKEN_TEST', $this->tokenFor('test'));
        $this->assertSame('TOKEN_DEMO', $this->tokenFor('demo'));
        $this->assertSame('TOKEN_PRODUCTION', $this->tokenFor('production'));

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee('TOKEN_TEST')
            ->assertDontSee('TOKEN_DEMO')
            ->assertDontSee('TOKEN_PRODUCTION');
    }

    public function test_token_is_not_flashed_or_saved_after_validation_failure(): void
    {
        $token = 'SECRET_DO_NOT_FLASH';

        $response = $this->from(route('integrations.ksef.edit'))
            ->put(route('integrations.ksef.update'), $this->payload([
                'name' => '',
                'api_token' => $token,
            ]));

        $response
            ->assertRedirect(route('integrations.ksef.edit'))
            ->assertSessionHasErrors('name');

        $this->assertNull(session()->getOldInput('api_token'));
        $this->assertArrayNotHasKey('api_token', session()->getOldInput());
        $this->assertSame(0, KsefCredential::query()->count());

        $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee($token);
    }

    public function test_replacing_api_token_clears_runtime_only_for_its_environment(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'OLD_TEST_TOKEN',
        ]))->assertSessionDoesntHaveErrors();
        $testCredential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $this->fillRuntimeState($testCredential, 'TEST');
        $demoCredential = KsefCredential::query()->create([
            'environment' => KsefEnvironment::Demo,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'DEMO_TOKEN',
        ]);
        $this->fillRuntimeState($demoCredential, 'DEMO');

        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'NEW_TEST_TOKEN',
        ]))->assertSessionDoesntHaveErrors();

        $testCredential->refresh();
        $this->assertSame('NEW_TEST_TOKEN', $testCredential->api_token);
        $this->assertRuntimeStateCleared($testCredential);
        $demoCredential->refresh();
        $this->assertSame('DEMO_TOKEN', $demoCredential->api_token);
        $this->assertSame('DEMO_ACCESS', $demoCredential->access_token);
        $this->assertSame('DEMO_REFRESH', $demoCredential->refresh_token);
        $this->assertSame(KsefConnectionTestStatus::Success, $demoCredential->last_test_status);
    }

    public function test_context_nip_change_clears_runtime_for_all_environments_but_preserves_api_tokens(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'TEST_TOKEN',
        ]))->assertSessionDoesntHaveErrors();

        foreach (KsefEnvironment::cases() as $environment) {
            $credential = KsefCredential::query()->firstOrCreate(
                ['environment' => $environment],
                [
                    'authentication_method' => KsefAuthenticationMethod::Token,
                    'api_token' => strtoupper($environment->value).'_TOKEN',
                ],
            );

            if ($credential->api_token === null) {
                $credential->forceFill(['api_token' => strtoupper($environment->value).'_TOKEN'])->save();
            }

            $this->fillRuntimeState($credential, strtoupper($environment->value));
        }

        $this->put(route('integrations.ksef.update'), $this->payload([
            'context_nip' => '0987654321',
            'api_token' => '',
        ]))->assertSessionDoesntHaveErrors();

        foreach (KsefEnvironment::cases() as $environment) {
            $credential = KsefCredential::query()->where('environment', $environment->value)->firstOrFail();
            $this->assertSame(strtoupper($environment->value).'_TOKEN', $credential->api_token);
            $this->assertRuntimeStateCleared($credential);
        }
    }

    public function test_unrelated_settings_change_preserves_runtime_authentication_and_test_state(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'api_token' => 'TEST_TOKEN',
        ]))->assertSessionDoesntHaveErrors();
        $credential = KsefCredential::query()->where('environment', 'test')->firstOrFail();
        $credential->forceFill([
            'authentication_certificate' => 'FAKE_CERTIFICATE_MATERIAL',
            'authentication_private_key' => 'FAKE_PRIVATE_KEY_MATERIAL',
        ])->save();
        $this->fillRuntimeState($credential, 'UNCHANGED');

        $this->put(route('integrations.ksef.update'), $this->payload([
            'automatic_submission' => true,
            'zero_vat_classification' => 'export',
            'default_split_payment' => true,
            'api_token' => '',
        ]))->assertSessionDoesntHaveErrors();

        $credential->refresh();
        $this->assertSame('TEST_TOKEN', $credential->api_token);
        $this->assertSame('FAKE_CERTIFICATE_MATERIAL', $credential->authentication_certificate);
        $this->assertSame('FAKE_PRIVATE_KEY_MATERIAL', $credential->authentication_private_key);
        $this->assertTrue(KsefSetting::query()->firstOrFail()->default_split_payment);
        $this->assertSame('UNCHANGED_ACCESS', $credential->access_token);
        $this->assertSame('UNCHANGED_REFRESH', $credential->refresh_token);
        $this->assertSame(KsefConnectionTestStatus::Success, $credential->last_test_status);
        $this->assertTrue($credential->last_test_invoice_write);
        $this->assertSame('UNCHANGED warning', $credential->last_system_warning);
    }

    private function tokenFor(string $environment): string
    {
        return KsefCredential::query()
            ->where('environment', $environment)
            ->firstOrFail()
            ->api_token;
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

    private function assertTokenEnvironmentInput(string $html, string $environment): void
    {
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*name="api_token_environment")(?=[^>]*value="'.preg_quote($environment, '/').'")(?=[^>]*data-ksef-token-environment)[^>]*>/s',
            $html,
        );
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
}
