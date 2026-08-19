<?php

namespace Tests\Feature\Ksef;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefPaymentSourceKind;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefPaymentMethodMapping;
use Modules\Ksef\Services\KsefPaymentMethodMappingService;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\TestCase;

class KsefPaymentMethodSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_schema_enum_and_default_configuration_match_fa3_contract(): void
    {
        $settings = app(KsefSettingsService::class)->get();

        $this->assertTrue(Schema::hasColumn('ksef_settings', 'default_payment_type'));
        $this->assertTrue(Schema::hasTable('ksef_payment_method_mappings'));
        $this->assertTrue(Schema::hasColumns('ksef_payment_method_mappings', [
            'source_kind',
            'source_key',
            'source_label',
            'target_type',
        ]));
        $this->assertSame(KsefPaymentType::Original, $settings->default_payment_type);
        $this->assertSame(
            ['payment_method', 'cash_on_delivery'],
            array_map(static fn (KsefPaymentSourceKind $kind): string => $kind->value, KsefPaymentSourceKind::cases()),
        );
        $this->assertSame([
            'original' => ['Oryginalny opis z zamówienia', null],
            'cash' => ['Gotówka', '1'],
            'card' => ['Karta', '2'],
            'voucher' => ['Bon', '3'],
            'cheque' => ['Czek', '4'],
            'credit' => ['Kredyt', '5'],
            'transfer' => ['Przelew', '6'],
            'mobile' => ['Mobilna', '7'],
        ], collect(KsefPaymentType::cases())->mapWithKeys(fn (KsefPaymentType $type): array => [
            $type->value => [$type->label(), $type->fa3Code()],
        ])->all());
    }

    public function test_namespace_migration_preserves_existing_ordinary_and_cod_mappings(): void
    {
        $migration = require database_path(
            'migrations/2026_08_13_060200_namespace_ksef_payment_method_mappings.php',
        );
        $migration->down();
        DB::table('ksef_payment_method_mappings')->insert([
            [
                'source_key' => 'legacy provider',
                'source_label' => 'Legacy Provider',
                'target_type' => 'card',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
                'source_label' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
                'target_type' => 'cash',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration->up();

        $this->assertDatabaseHas('ksef_payment_method_mappings', [
            'source_kind' => KsefPaymentSourceKind::PaymentMethod->value,
            'source_key' => 'legacy provider',
            'target_type' => 'card',
        ]);
        $this->assertDatabaseHas('ksef_payment_method_mappings', [
            'source_kind' => KsefPaymentSourceKind::CashOnDelivery->value,
            'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
            'target_type' => 'cash',
        ]);
    }

    public function test_payment_types_tab_discovers_normalized_active_order_methods_and_keeps_persisted_rows(): void
    {
        $this->order('Przelew');
        $this->order(' przelew ');
        $this->order('PRZELEW');
        $this->order('Przelew na konto');
        $deleted = $this->order('Tylko usunięte zamówienie');
        $deleted->delete();
        KsefPaymentMethodMapping::query()->create([
            'source_key' => 'legacy provider',
            'source_label' => 'Legacy Provider',
            'target_type' => KsefPaymentType::Card,
        ]);

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->assertOk()
            ->assertViewHas('activeTab', 'payment-types')
            ->assertSeeText('Typy płatności')
            ->assertSeeText('Domyślny typ płatności dla nieustawionych poniżej')
            ->assertSeeText(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL)
            ->assertSeeText('Przelew')
            ->assertSeeText('Przelew na konto')
            ->assertSeeText('Legacy Provider')
            ->assertDontSeeText('Tylko usunięte zamówienie')
            ->assertSeeText('--- użyj domyślnego ---')
            ->assertSee('name="default_payment_type"', false)
            ->assertSee('value="original" selected', false)
            ->assertSee('data-ksef-payment-types-form', false)
            ->assertSee('name="_token"', false);

        $rows = $response->viewData('paymentMethods')->all();
        $this->assertSame(KsefPaymentSourceKind::CashOnDelivery->value, $rows[0]['source_kind']);
        $this->assertSame(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY, $rows[0]['source_key']);
        $this->assertSame(1, collect($rows)->where('source_key', 'przelew')->count());
        $this->assertSame(1, collect($rows)->where('source_key', 'przelew na konto')->count());
        $this->assertSame('card', collect($rows)->firstWhere('source_key', 'legacy provider')['target_type']);

        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*data-ksef-tab="payment-types")(?=[^>]*class="[^"]*is-active)[^>]*>/s',
            $response->getContent(),
        );
    }

    public function test_save_persists_default_and_overrides_without_invalidating_auth_runtime(): void
    {
        $this->order('PayU');
        $settings = app(KsefSettingsService::class)->get();
        $credential = KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_API_TOKEN',
            'access_token' => 'FAKE_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
            'last_tested_at' => now(),
            'last_test_status' => KsefConnectionTestStatus::Success,
            'last_test_message' => 'Połączenie działa.',
            'last_test_invoice_write' => true,
        ]);
        $credentialBefore = $credential->fresh()->getAttributes();

        $this->put(route('integrations.ksef.payment-types.update'), [
            'default_payment_type' => 'transfer',
            'mappings' => [
                [
                    'source_kind' => KsefPaymentSourceKind::CashOnDelivery->value,
                    'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
                    'target_type' => 'cash',
                ],
                $this->mappingPayload('payu', 'original'),
            ],
        ])->assertRedirect(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->assertSessionHas('success', 'Zapisano mapowanie typów płatności.');

        $this->assertSame(KsefPaymentType::Transfer, $settings->fresh()->default_payment_type);
        $this->assertDatabaseHas('ksef_payment_method_mappings', [
            'source_kind' => KsefPaymentSourceKind::CashOnDelivery->value,
            'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
            'source_label' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
            'target_type' => 'cash',
        ]);
        $this->assertDatabaseHas('ksef_payment_method_mappings', [
            'source_kind' => KsefPaymentSourceKind::PaymentMethod->value,
            'source_key' => 'payu',
            'source_label' => 'PayU',
            'target_type' => 'original',
        ]);
        $this->assertSame($credentialBefore, $credential->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_use_default_deletes_override_and_invalid_source_rolls_back_entire_save(): void
    {
        $this->order('Allegro Finance');
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['default_payment_type' => KsefPaymentType::Original])->save();
        KsefPaymentMethodMapping::query()->create([
            'source_key' => 'allegro finance',
            'source_label' => 'Allegro Finance',
            'target_type' => KsefPaymentType::Transfer,
        ]);

        $this->put(route('integrations.ksef.payment-types.update'), [
            'default_payment_type' => 'card',
            'mappings' => [
                $this->mappingPayload('allegro finance', null),
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseMissing('ksef_payment_method_mappings', ['source_key' => 'allegro finance']);
        $this->assertSame('card', app(KsefPaymentMethodMappingService::class)->resolve('Allegro Finance', false)['type']);

        $this->from(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->put(route('integrations.ksef.payment-types.update'), [
                'default_payment_type' => 'mobile',
                'mappings' => [
                    $this->mappingPayload('allegro finance', 'cash'),
                    $this->mappingPayload('browser invented source', 'credit'),
                ],
            ])
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->assertSessionHasErrors('mappings');

        $this->assertSame(KsefPaymentType::Card, $settings->fresh()->default_payment_type);
        $this->assertDatabaseMissing('ksef_payment_method_mappings', ['source_key' => 'allegro finance']);
        $this->assertDatabaseMissing('ksef_payment_method_mappings', ['source_key' => 'browser invented source']);
    }

    public function test_invalid_payment_types_are_rejected_without_partial_update(): void
    {
        $this->order('PayU');
        $settings = app(KsefSettingsService::class)->get();

        $this->from(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->put(route('integrations.ksef.payment-types.update'), [
                'default_payment_type' => 'wire',
                'mappings' => [
                    $this->mappingPayload('payu', 'card'),
                ],
            ])
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->assertSessionHasErrors('default_payment_type');

        $this->assertSame(KsefPaymentType::Original, $settings->fresh()->default_payment_type);
        $this->assertDatabaseMissing('ksef_payment_method_mappings', ['source_key' => 'payu']);

        $this->from(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->put(route('integrations.ksef.payment-types.update'), [
                'default_payment_type' => 'transfer',
                'mappings' => [
                    $this->mappingPayload('payu', 'wire'),
                ],
            ])
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->assertSessionHasErrors('mappings.0.target_type');

        $this->assertSame(KsefPaymentType::Original, $settings->fresh()->default_payment_type);
        $this->assertDatabaseMissing('ksef_payment_method_mappings', ['source_key' => 'payu']);
    }

    public function test_resolver_applies_specific_cod_and_default_rules_without_inventing_empty_source(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['default_payment_type' => KsefPaymentType::Original])->save();
        $service = app(KsefPaymentMethodMappingService::class);

        $this->assertSame([
            'version' => 1,
            'source_key' => 'provider xyz',
            'source_label' => 'Provider XYZ',
            'type' => 'original',
            'description' => 'Provider XYZ',
        ], $service->resolve('  Provider   XYZ  ', false));
        $this->assertSame([
            'version' => 1,
            'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
            'source_label' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
            'type' => 'original',
            'description' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
        ], $service->resolve('PayU', true));
        $this->assertSame([
            'version' => 1,
            'source_key' => null,
            'source_label' => null,
            'type' => null,
        ], $service->resolve(" \t ", false));

        KsefPaymentMethodMapping::query()->create([
            'source_kind' => KsefPaymentSourceKind::CashOnDelivery,
            'source_key' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
            'source_label' => KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL,
            'target_type' => KsefPaymentType::Cash,
        ]);
        KsefPaymentMethodMapping::query()->create([
            'source_key' => 'blik_code',
            'source_label' => 'BLIK_CODE',
            'target_type' => KsefPaymentType::Mobile,
        ]);

        $this->assertSame('1', $service->resolve('PayU', true)['fa3_code']);
        $this->assertSame('7', $service->resolve(' BLIK_CODE ', false)['fa3_code']);
    }

    public function test_cod_and_identically_named_order_method_have_disjoint_configuration_identity(): void
    {
        $this->order(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY);

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->assertOk()
            ->assertSeeText(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_LABEL)
            ->assertSeeText(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY)
            ->assertSee('value="cash_on_delivery"', false)
            ->assertSee('value="payment_method"', false);

        $rows = collect($response->viewData('paymentMethods'))
            ->where('source_key', KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY)
            ->values();
        $this->assertSame([
            KsefPaymentSourceKind::CashOnDelivery->value,
            KsefPaymentSourceKind::PaymentMethod->value,
        ], $rows->pluck('source_kind')->all());

        $this->put(route('integrations.ksef.payment-types.update'), [
            'default_payment_type' => 'original',
            'mappings' => [
                $this->mappingPayload(
                    KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
                    'cash',
                    KsefPaymentSourceKind::CashOnDelivery,
                ),
                $this->mappingPayload(
                    KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY,
                    'mobile',
                ),
            ],
        ])->assertSessionDoesntHaveErrors();

        $service = app(KsefPaymentMethodMappingService::class);
        $this->assertSame('1', $service->resolve('ignored', true)['fa3_code']);
        $this->assertSame(
            '7',
            $service->resolve(KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY, false)['fa3_code'],
        );
        $this->assertSame(2, KsefPaymentMethodMapping::query()
            ->where('source_key', KsefPaymentMethodMappingService::CASH_ON_DELIVERY_SOURCE_KEY)
            ->count());
    }

    public function test_invalid_source_kind_is_rejected_without_partial_update(): void
    {
        $this->order('PayU');
        $settings = app(KsefSettingsService::class)->get();

        $this->from(route('integrations.ksef.edit', ['tab' => 'payment-types']))
            ->put(route('integrations.ksef.payment-types.update'), [
                'default_payment_type' => 'cash',
                'mappings' => [[
                    'source_kind' => 'browser_invented_kind',
                    'source_key' => 'payu',
                    'target_type' => 'card',
                ]],
            ])
            ->assertSessionHasErrors('mappings.0.source_kind');

        $this->assertSame(KsefPaymentType::Original, $settings->fresh()->default_payment_type);
        $this->assertDatabaseMissing('ksef_payment_method_mappings', ['source_key' => 'payu']);
    }

    private function order(?string $paymentMethod): Order
    {
        return Order::query()->create([
            'source' => 'test',
            'status' => Order::STATUS_NEW,
            'payment_method' => $paymentMethod,
        ]);
    }

    /** @return array{source_kind: string, source_key: string, target_type: ?string} */
    private function mappingPayload(
        string $sourceKey,
        ?string $targetType,
        KsefPaymentSourceKind $sourceKind = KsefPaymentSourceKind::PaymentMethod,
    ): array {
        return [
            'source_kind' => $sourceKind->value,
            'source_key' => $sourceKey,
            'target_type' => $targetType,
        ];
    }
}
