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

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'KSeF',
            'environment' => 'test',
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
    }
}
