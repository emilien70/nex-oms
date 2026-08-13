<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    private function tokenFor(string $environment): string
    {
        return KsefCredential::query()
            ->where('environment', $environment)
            ->firstOrFail()
            ->api_token;
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
            'automatic_submission' => false,
            'send_without_buyer_nip' => false,
            'include_recipient_data' => false,
            'include_buyer_contact_data' => false,
            'include_additional_information' => false,
            'include_order_reference' => true,
            'include_bank_account' => true,
            'include_gtu' => true,
            'include_sale_date' => true,
        ], $overrides);

        if (! array_key_exists('api_token_environment', $overrides)) {
            $payload['api_token_environment'] = $payload['environment'];
        }

        return $payload;
    }
}
