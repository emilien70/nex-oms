<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Tests\TestCase;

class PrestashopSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_is_available_without_exposing_the_api_key(): void
    {
        $account = $this->account();

        $this->get(route('integrations.prestashop.edit'))
            ->assertOk()
            ->assertSeeText('Sklep internetowy')
            ->assertSeeText('Wymagane uprawnienia API')
            ->assertSee('Zapisano - pozostaw puste bez zmian')
            ->assertDontSee((string) $account->api_key);
    }

    public function test_settings_are_saved_encrypted_and_blank_key_preserves_the_credential(): void
    {
        $this->put(route('integrations.prestashop.update'), $this->settingsPayload([
            'base_url' => 'https://multi-click.pl/api/',
            'api_key' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
        ]))->assertRedirect(route('integrations.prestashop.edit'));

        $account = PrestashopAccount::query()->firstOrFail();

        $this->assertSame('https://multi-click.pl', $account->base_url);
        $this->assertSame(
            'https://multi-click.pl/index.php?controller=product&id_product=[product_id]',
            $account->product_url_template,
        );
        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', $account->api_key);
        $this->assertNotSame(
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
            DB::table('prestashop_accounts')->value('api_key'),
        );

        $this->put(route('integrations.prestashop.update'), $this->settingsPayload([
            'name' => 'Sklep główny',
            'api_key' => '',
        ]))->assertRedirect(route('integrations.prestashop.edit'));

        $account->refresh();

        $this->assertSame('Sklep główny', $account->name);
        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', $account->api_key);
    }

    public function test_product_url_template_requires_token_and_store_domain(): void
    {
        $this->from(route('integrations.prestashop.edit'))
            ->put(route('integrations.prestashop.update'), $this->settingsPayload([
                'product_url_template' => 'https://multi-click.pl/product/96',
            ]))
            ->assertSessionHasErrors('product_url_template');

        $this->from(route('integrations.prestashop.edit'))
            ->put(route('integrations.prestashop.update'), $this->settingsPayload([
                'product_url_template' => 'https://other-shop.example/product/[product_id]',
            ]))
            ->assertSessionHasErrors('product_url_template');
    }

    public function test_api_key_is_not_flashed_to_the_session_after_validation_failure(): void
    {
        $response = $this->from(route('integrations.prestashop.edit'))
            ->put(route('integrations.prestashop.update'), $this->settingsPayload([
                'name' => '',
                'api_key' => 'MUSTNOTENTERSESSION123456789012',
            ]));

        $response->assertSessionHasErrors(['name']);
        $this->assertArrayNotHasKey('api_key', session()->get('_old_input', []));
    }

    public function test_activation_requires_a_successful_test_for_current_credentials(): void
    {
        $account = $this->account();

        $this->from(route('integrations.prestashop.edit'))
            ->put(route('integrations.prestashop.update'), $this->settingsPayload(['is_active' => '1']))
            ->assertSessionHasErrors(['is_active']);

        $this->assertFalse($account->fresh()->is_active);

        $account->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => PrestashopConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ])->save();

        $this->put(route('integrations.prestashop.update'), $this->settingsPayload(['is_active' => '1']))
            ->assertRedirect(route('integrations.prestashop.edit'));

        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_changing_connection_details_deactivates_account_and_clears_discovery(): void
    {
        $account = $this->account([
            'is_active' => true,
            'shop_id' => 1,
            'shop_name' => 'multi-click.pl',
            'last_test_status' => PrestashopConnectionTestStatus::Success,
        ]);
        $account->orderStates()->create([
            'external_id' => 1,
            'name' => 'Nowe',
            'refreshed_at' => now(),
        ]);

        $this->put(route('integrations.prestashop.update'), $this->settingsPayload([
            'base_url' => 'https://shop.multi-click.pl',
            'product_url_template' => 'https://shop.multi-click.pl/index.php?controller=product&id_product=[product_id]',
            'is_active' => '1',
        ]))->assertRedirect(route('integrations.prestashop.edit'));

        $account->refresh();

        $this->assertFalse($account->is_active);
        $this->assertNull($account->last_test_status);
        $this->assertNull($account->shop_id);
        $this->assertSame(0, $account->orderStates()->count());
    }

    public function test_connection_test_discovers_single_shop_metadata_and_order_states(): void
    {
        $account = $this->account();
        Http::fake(fn (Request $request) => $this->prestashopResponse($request));

        $this->post(route('integrations.prestashop.test-connection'))
            ->assertRedirect(route('integrations.prestashop.edit'))
            ->assertSessionHas('success', function (string $message): bool {
                return str_contains($message, 'Połączenie z PrestaShop działa poprawnie.')
                    && str_contains($message, 'statusy zamówień: 3');
            });

        $account->refresh();

        $this->assertSame(PrestashopConnectionTestStatus::Success, $account->last_test_status);
        $this->assertSame(1, $account->shop_id);
        $this->assertSame('Sklep główny', $account->shop_name);
        $this->assertSame('8.2.7', $account->detected_version);
        $this->assertSame('pl', $account->language_iso_code);
        $this->assertSame('bl_order', $account->order_enrichment_resource);
        $this->assertSame(['PLN', 'EUR'], collect($account->available_currencies)->pluck('iso_code')->all());
        $this->assertSame(3, $account->orderStates()->count());
        $this->assertTrue($account->orderStates()->where('external_id', 2)->firstOrFail()->paid);
        $this->assertTrue($account->orderStates()->where('external_id', 4)->firstOrFail()->shipped);

        Http::assertSent(function (Request $request) use ($account): bool {
            return parse_url($request->url(), PHP_URL_PATH) === '/api/shops'
                && $request->method() === 'GET'
                && ($request->header('Authorization')[0] ?? null) === 'Basic '.base64_encode($account->api_key.':')
                && ($request->header('Output-Format')[0] ?? null) === 'JSON';
        });
        Http::assertSent(function (Request $request): bool {
            return parse_url($request->url(), PHP_URL_PATH) === '/api/order_states'
                && ($request->header('Output-Format')[0] ?? null) === 'XML'
                && $request['output_format'] === 'XML'
                && $request['language'] === 1;
        });
        Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/');
        Http::assertSentCount(13);

        $this->get(route('integrations.prestashop.edit'))
            ->assertOk()
            ->assertSeeText('Wykryta konfiguracja')
            ->assertSeeText('Płatność zaakceptowana')
            ->assertSeeText('Wysłane');
    }

    public function test_connection_refresh_preserves_mappings_and_marks_missing_states_unavailable(): void
    {
        $account = $this->account();
        $missingState = $account->orderStates()->create([
            'external_id' => 99,
            'name' => 'Historyczny status',
            'is_available' => true,
            'refreshed_at' => now()->subDay(),
        ]);
        OrderStatusSetting::syncDefaults();
        $newStatus = OrderStatusSetting::query()->where('status', 'new')->firstOrFail();
        $mapping = $account->inboundStatusMappings()->create([
            'external_order_state_id' => $missingState->external_id,
            'order_status_setting_id' => $newStatus->id,
        ]);
        Http::fake(fn (Request $request) => $this->prestashopResponse($request));

        $this->post(route('integrations.prestashop.test-connection'))
            ->assertRedirect(route('integrations.prestashop.edit'));

        $this->assertFalse($missingState->fresh()->is_available);
        $this->assertDatabaseHas('prestashop_inbound_status_mappings', [
            'id' => $mapping->id,
            'prestashop_account_id' => $account->id,
            'external_order_state_id' => 99,
            'order_status_setting_id' => $newStatus->id,
        ]);
    }

    public function test_missing_read_permissions_fail_safely_and_deactivate_account(): void
    {
        $account = $this->account([
            'is_active' => true,
            'last_test_status' => PrestashopConnectionTestStatus::Success,
        ]);

        Http::fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/api/currencies') {
                return Http::response([], 403);
            }

            return $this->prestashopResponse($request);
        });

        $this->post(route('integrations.prestashop.test-connection'))
            ->assertRedirect(route('integrations.prestashop.edit'))
            ->assertSessionHas('error', 'Brak uprawnienia odczytu zasobu PrestaShop: currencies.');

        $account->refresh();

        $this->assertSame(PrestashopConnectionTestStatus::Error, $account->last_test_status);
        $this->assertFalse($account->is_active);
        $this->assertStringNotContainsString((string) $account->api_key, (string) $account->last_test_message);
        Http::assertSentCount(3);
    }

    public function test_more_than_one_active_shop_is_rejected_for_single_store_configuration(): void
    {
        $account = $this->account();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/api/shops') {
                return Http::response([
                    'shops' => [
                        ['id' => 1, 'name' => 'Sklep 1', 'active' => '1'],
                        ['id' => 2, 'name' => 'Sklep 2', 'active' => '1'],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $this->post(route('integrations.prestashop.test-connection'))
            ->assertSessionHas(
                'error',
                'Wykryto więcej niż jeden aktywny sklep. Ta konfiguracja obsługuje pojedynczy sklep PrestaShop.',
            );

        $this->assertSame(PrestashopConnectionTestStatus::Error, $account->fresh()->last_test_status);
        Http::assertSentCount(1);
    }

    public function test_connection_test_without_credentials_does_not_use_the_network(): void
    {
        Http::fake();

        $this->post(route('integrations.prestashop.test-connection'))
            ->assertRedirect(route('integrations.prestashop.edit'))
            ->assertSessionHas('error', 'Najpierw zapisz adres sklepu i Klucz API PrestaShop.');

        Http::assertNothingSent();
    }

    public function test_store_url_cannot_change_after_an_order_was_imported(): void
    {
        $account = $this->account();
        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => '13581',
            'status' => 'new',
            'currency' => 'PLN',
        ]);
        PrestashopOrderLink::query()->create([
            'prestashop_account_id' => $account->getKey(),
            'order_id' => $order->getKey(),
            'external_order_id' => 13581,
            'external_reference' => '13581',
            'source_hash' => str_repeat('a', 64),
            'imported_at' => now(),
        ]);

        $this->from(route('integrations.prestashop.edit'))
            ->put(route('integrations.prestashop.update'), $this->settingsPayload([
                'base_url' => 'https://other-shop.example',
                'product_url_template' => 'https://other-shop.example/index.php?controller=product&id_product=[product_id]',
            ]))
            ->assertRedirect(route('integrations.prestashop.edit'))
            ->assertSessionHasErrors('base_url');

        $this->assertSame('https://multi-click.pl', $account->fresh()->base_url);
    }

    private function account(array $overrides = []): PrestashopAccount
    {
        return PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => false,
        ], $overrides));
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'product_url_template' => 'https://multi-click.pl/index.php?controller=product&id_product=[product_id]',
            'api_key' => '',
            'is_active' => '0',
        ], $overrides);
    }

    private function prestashopResponse(Request $request)
    {
        return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
            '/api/shops' => Http::response([
                'shops' => [
                    ['id' => 1, 'name' => 'Sklep główny', 'active' => '1'],
                ],
            ], 200, ['PSWS-Version' => '8.2.7']),
            '/api/languages' => Http::response([
                'languages' => [
                    ['id' => 1, 'name' => 'Polski', 'iso_code' => 'pl', 'active' => '1'],
                    ['id' => 2, 'name' => 'English', 'iso_code' => 'en', 'active' => '1'],
                ],
            ]),
            '/api/currencies' => Http::response([
                'currencies' => [
                    ['id' => 1, 'name' => 'Złoty', 'iso_code' => 'PLN', 'active' => '1', 'deleted' => '0'],
                    ['id' => 2, 'name' => 'Euro', 'iso_code' => 'EUR', 'active' => '1', 'deleted' => '0'],
                ],
            ]),
            '/api/order_states' => Http::response($this->orderStatesXml(), 200, ['Content-Type' => 'text/xml']),
            '/api/orders',
            '/api/order_details',
            '/api/addresses',
            '/api/customers',
            '/api/carriers',
            '/api/countries',
            '/api/states',
            '/api/order_payments' => Http::response([], 200),
            '/api/bl_order' => Http::response(['orders' => [['id' => 13581]]], 200),
            default => Http::response([], 404),
        };
    }

    private function orderStatesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<prestashop>
  <order_states>
    <order_state><id>1</id><name><language id="1">Oczekiwanie na płatność</language></name><color>#4169E1</color></order_state>
    <order_state><id>2</id><name><language id="1">Płatność zaakceptowana</language></name><color>#32CD32</color><paid>1</paid><logable>1</logable></order_state>
    <order_state><id>4</id><name><language id="1">Wysłane</language></name><color>#8A2BE2</color><shipped>1</shipped></order_state>
  </order_states>
</prestashop>
XML;
    }
}
