<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefPaymentType;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\TestCase;

class KsefSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_exposes_single_configuration_and_expected_tabs_and_environments(): void
    {
        $response = $this->get(route('integrations.ksef.edit'));

        $response
            ->assertOk()
            ->assertSeeText('KSeF')
            ->assertSeeText('Połączenie')
            ->assertSeeText('Serie numeracji')
            ->assertSee('data-ksef-tab="export"', false)
            ->assertSee('data-ksef-tab="payment-types"', false)
            ->assertSee('name="environment"', false)
            ->assertSee('value="test"', false)
            ->assertSee('value="demo"', false)
            ->assertSee('value="production"', false)
            ->assertSeeText('Środowisko testowe')
            ->assertSeeText('Środowisko przedprodukcyjne')
            ->assertSeeText('Środowisko produkcyjne')
            ->assertSeeText('Integracja KSeF aktywna')
            ->assertSeeText('Traktuj stawkę VAT 0% jako')
            ->assertSeeText('WDT')
            ->assertSeeText('Eksport towarów')
            ->assertSeeText('Sprzedaż krajowa 0%')
            ->assertSeeText('MPP – Mechanizm podzielonej płatności')
            ->assertSeeText('Domyślna wartość dla nowych Faktur VAT')
            ->assertSeeText('NEX-OMS')
            ->assertSeeText('równoległego automatycznego przekazywania')
            ->assertDontSeeText('Przekazuj datę sprzedaży')
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSeeText('Przetestuj połączenie')
            ->assertViewHas('environmentOptions', fn (array $options): bool => collect($options)
                ->pluck('value')
                ->all() === ['test', 'demo', 'production'])
            ->assertViewHas('zeroVatClassifications', fn (array $options): bool => collect($options)
                ->pluck('value')
                ->all() === ['wdt', 'export', 'domestic']);

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*data-ksef-tab="export")(?=[^>]*\bdisabled\b)[^>]*>/s',
            $response->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*data-ksef-tab="payment-types")(?=[^>]*href="[^"]*tab=payment-types)[^>]*>/s',
            $response->getContent(),
        );

        $this->assertSame(1, KsefSetting::query()->count());
        $this->assertDatabaseHas('ksef_settings', [
            'singleton_key' => KsefSetting::SINGLETON_KEY,
            'name' => 'KSeF',
            'environment' => 'test',
            'is_active' => false,
            'automatic_submission' => false,
            'include_order_reference' => true,
            'include_bank_account' => true,
            'include_gtu' => true,
            'zero_vat_classification' => 'wdt',
            'default_split_payment' => false,
            'default_payment_type' => 'original',
        ]);

        $settings = KsefSetting::query()->firstOrFail();
        $this->assertFalse($settings->is_active);
        $this->assertSame(KsefZeroVatClassification::Wdt, $settings->zero_vat_classification);
        $this->assertFalse($settings->default_split_payment);
        $this->assertSame(KsefPaymentType::Original, $settings->default_payment_type);
        $this->assertTrue(Schema::hasColumns('ksef_settings', ['is_active', 'zero_vat_classification', 'default_split_payment', 'default_payment_type']));
        $this->assertFalse(Schema::hasColumn('ksef_settings', 'include_sale_date'));
        $this->assertTrue(Schema::hasColumns('ksef_credentials', [
            'access_token',
            'access_token_valid_until',
            'refresh_token',
            'refresh_token_valid_until',
            'last_tested_at',
            'last_test_status',
            'last_test_message',
            'last_test_invoice_write',
            'last_system_warning',
        ]));
    }

    public function test_token_configuration_map_normalizes_cast_environments_to_string_keys(): void
    {
        KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'TEST_API_TOKEN_DO_NOT_EXPOSE',
        ]);
        KsefCredential::query()->create([
            'environment' => KsefEnvironment::Demo,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => null,
        ]);

        $this->assertSame([
            'test' => true,
            'demo' => false,
            'production' => false,
        ], app(KsefSettingsService::class)->tokenConfiguredByEnvironment());
    }

    public function test_token_configuration_map_recognizes_tokens_for_all_environments(): void
    {
        foreach (KsefEnvironment::cases() as $environment) {
            KsefCredential::query()->create([
                'environment' => $environment,
                'authentication_method' => KsefAuthenticationMethod::Token,
                'api_token' => strtoupper($environment->value).'_API_TOKEN_DO_NOT_EXPOSE',
            ]);
        }

        $this->assertSame([
            'test' => true,
            'demo' => true,
            'production' => true,
        ], app(KsefSettingsService::class)->tokenConfiguredByEnvironment());
    }

    public function test_persisted_token_enables_connection_test_for_selected_environment(): void
    {
        $token = 'TEST_API_TOKEN_DO_NOT_EXPOSE';
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => KsefEnvironment::Test,
            'context_nip' => '1234567890',
        ])->save();
        KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => $token,
        ]);

        $response = $this->get(route('integrations.ksef.edit'))
            ->assertOk()
            ->assertDontSee($token)
            ->assertViewHas('tokenConfiguredByEnvironment', [
                'test' => true,
                'demo' => false,
                'production' => false,
            ]);

        $html = $response->getContent();
        preg_match('/<button(?=[^>]*data-ksef-test-button)[^>]*>/s', $html, $buttonMatch);
        preg_match('/<div class="ksef-help" data-ksef-test-help>\s*(.*?)\s*<\/div>/s', $html, $helpMatch);

        $this->assertArrayHasKey(0, $buttonMatch);
        $this->assertStringNotContainsString('disabled', $buttonMatch[0]);
        $this->assertArrayHasKey(1, $helpMatch);

        $helpText = trim(html_entity_decode(strip_tags($helpMatch[1])));
        $this->assertStringContainsString("Test po\u{0142}\u{0105}czenia u\u{017C}ywa zapisanej konfiguracji.", $helpText);
        $this->assertStringNotContainsString('Najpierw zapisz Token KSeF.', $helpText);
    }

    public function test_repeated_saves_update_one_singleton_and_persist_each_environment(): void
    {
        Http::preventStrayRequests();

        foreach (['test', 'demo', 'production'] as $environment) {
            $this->put(route('integrations.ksef.update'), $this->payload([
                'environment' => $environment,
                'name' => 'KSeF '.$environment,
            ]))->assertRedirect(route('integrations.ksef.edit'));
        }

        $this->assertSame(1, KsefSetting::query()->count());
        $this->assertDatabaseHas('ksef_settings', [
            'singleton_key' => KsefSetting::SINGLETON_KEY,
            'name' => 'KSeF production',
            'environment' => 'production',
        ]);
    }

    public function test_invalid_environment_values_are_rejected(): void
    {
        foreach (['sandbox', 'prod', 'preprod', 'abc'] as $environment) {
            $this->from(route('integrations.ksef.edit'))
                ->put(route('integrations.ksef.update'), $this->payload([
                    'environment' => $environment,
                ]))
                ->assertRedirect(route('integrations.ksef.edit'))
                ->assertSessionHasErrors('environment');
        }

        $this->assertSame(0, KsefSetting::query()->count());
    }

    public function test_nip_is_normalized_without_accepting_letters_or_wrong_length(): void
    {
        Http::preventStrayRequests();

        $this->put(route('integrations.ksef.update'), $this->payload([
            'context_nip' => '123-456-78-90',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('ksef_settings', [
            'context_nip' => '1234567890',
        ]);

        foreach (['123456789', '12345678901', '123456789A'] as $invalidNip) {
            $this->from(route('integrations.ksef.edit'))
                ->put(route('integrations.ksef.update'), $this->payload([
                    'context_nip' => $invalidNip,
                ]))
                ->assertSessionHasErrors('context_nip');
        }

        $this->assertDatabaseHas('ksef_settings', [
            'context_nip' => '1234567890',
        ]);
    }

    public function test_all_basic_export_settings_are_persisted(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'is_active' => true,
            'automatic_submission' => true,
            'send_without_buyer_nip' => true,
            'include_recipient_data' => true,
            'include_buyer_contact_data' => true,
            'include_additional_information' => true,
            'include_order_reference' => false,
            'include_bank_account' => false,
            'include_gtu' => false,
            'zero_vat_classification' => 'export',
            'default_split_payment' => true,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('ksef_settings', [
            'is_active' => true,
            'automatic_submission' => true,
            'send_without_buyer_nip' => true,
            'include_recipient_data' => true,
            'include_buyer_contact_data' => true,
            'include_additional_information' => true,
            'include_order_reference' => false,
            'include_bank_account' => false,
            'include_gtu' => false,
            'zero_vat_classification' => 'export',
            'default_split_payment' => true,
        ]);
    }

    public function test_integration_active_switch_persists_without_creating_another_singleton(): void
    {
        Http::preventStrayRequests();

        $this->put(route('integrations.ksef.update'), $this->payload([
            'is_active' => true,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertTrue(KsefSetting::query()->firstOrFail()->is_active);

        $this->put(route('integrations.ksef.update'), $this->payload([
            'is_active' => false,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertFalse(KsefSetting::query()->firstOrFail()->is_active);
        $this->assertSame(1, KsefSetting::query()->count());
    }

    public function test_each_zero_vat_classification_is_persisted(): void
    {
        Http::preventStrayRequests();

        foreach (KsefZeroVatClassification::cases() as $classification) {
            $this->put(route('integrations.ksef.update'), $this->payload([
                'zero_vat_classification' => $classification->value,
            ]))->assertSessionDoesntHaveErrors();

            $this->assertSame(
                $classification,
                KsefSetting::query()->firstOrFail()->zero_vat_classification,
            );
        }
    }

    public function test_default_split_payment_is_persisted_as_a_boolean(): void
    {
        Http::preventStrayRequests();

        $this->put(route('integrations.ksef.update'), $this->payload([
            'default_split_payment' => true,
        ]))->assertSessionDoesntHaveErrors();
        $this->assertTrue(KsefSetting::query()->firstOrFail()->default_split_payment);

        $this->put(route('integrations.ksef.update'), $this->payload([
            'default_split_payment' => false,
        ]))->assertSessionDoesntHaveErrors();
        $this->assertFalse(KsefSetting::query()->firstOrFail()->default_split_payment);
    }

    public function test_invalid_zero_vat_classification_is_rejected_without_partial_update(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'name' => 'Konfiguracja bazowa',
        ]))->assertSessionDoesntHaveErrors();

        foreach (['WDT', 'ex', 'export_goods', 'domestic_zero', 'abc'] as $classification) {
            $before = KsefSetting::query()->firstOrFail()->getAttributes();

            $this->from(route('integrations.ksef.edit'))
                ->put(route('integrations.ksef.update'), $this->payload([
                    'name' => 'Nie zapisuj '.$classification,
                    'zero_vat_classification' => $classification,
                ]))
                ->assertRedirect(route('integrations.ksef.edit'))
                ->assertSessionHasErrors('zero_vat_classification');

            $this->assertSame($before, KsefSetting::query()->firstOrFail()->getAttributes());
        }
    }

    public function test_removed_include_sale_date_is_ignored_by_a_crafted_request(): void
    {
        $this->put(route('integrations.ksef.update'), $this->payload([
            'name' => 'Bez starego ustawienia',
            'include_sale_date' => false,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('ksef_settings', ['name' => 'Bez starego ustawienia']);
        $this->assertFalse(Schema::hasColumn('ksef_settings', 'include_sale_date'));
    }

    public function test_saving_configuration_has_no_invoice_side_effects(): void
    {
        $series = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Invoice->value)
            ->firstOrFail();
        $invoice = Invoice::query()->create([
            'invoice_series_id' => $series->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'SAFE/1/2026',
            'lock_version' => 7,
            'buyer_snapshot' => ['name' => 'Nabywca historyczny'],
            'tax_summary_snapshot' => [
                '0' => ['net' => '100.00', 'vat' => '0.00', 'gross' => '100.00'],
            ],
            'total_net' => '100.00',
            'total_vat' => '0.00',
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'amount_due' => '100.00',
        ]);
        $invoice->forceFill(['finalized_at' => now()])->saveQuietly();
        $item = $invoice->items()->create([
            'line_type' => 'product',
            'position' => 1,
            'name' => 'Produkt VAT 0%',
            'quantity' => '1.0000',
            'unit_price_net' => '100.0000',
            'unit_price_gross' => '100.0000',
            'total_net' => '100.00',
            'total_vat' => '0.00',
            'total_gross' => '100.00',
            'vat_rate' => '0.00',
            'vat_code' => null,
            'product_snapshot' => ['name' => 'Produkt VAT 0%'],
        ]);
        $before = $invoice->refresh()->getAttributes();
        $itemBefore = $item->refresh()->getAttributes();

        Http::preventStrayRequests();
        foreach (KsefZeroVatClassification::cases() as $classification) {
            $this->put(route('integrations.ksef.update'), $this->payload([
                'zero_vat_classification' => $classification->value,
            ]))->assertSessionDoesntHaveErrors();
        }

        $this->assertSame($before, $invoice->refresh()->getAttributes());
        $this->assertSame($itemBefore, $item->refresh()->getAttributes());
        $this->assertSame('0.00', $item->vat_rate);
        $this->assertNull($item->vat_code);
    }

    public function test_configuration_routes_do_not_expose_multi_integration_crud(): void
    {
        $this->assertFalse(app('router')->has('integrations.ksef.create'));
        $this->assertFalse(app('router')->has('integrations.ksef.destroy'));
        $this->assertFalse(app('router')->has('integrations.ksef.index'));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'KSeF',
            'environment' => 'test',
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
            'zero_vat_classification' => 'wdt',
            'default_split_payment' => false,
        ], $overrides);
    }
}
