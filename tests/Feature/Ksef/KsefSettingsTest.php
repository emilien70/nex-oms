<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Models\KsefSetting;
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
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSeeText('Przetestuj połączenie')
            ->assertViewHas('environmentOptions', fn (array $options): bool => collect($options)
                ->pluck('value')
                ->all() === ['test', 'demo', 'production']);

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*data-ksef-tab="export")(?=[^>]*\bdisabled\b)[^>]*>/s',
            $response->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*data-ksef-tab="payment-types")(?=[^>]*\bdisabled\b)[^>]*>/s',
            $response->getContent(),
        );

        $this->assertSame(1, KsefSetting::query()->count());
        $this->assertDatabaseHas('ksef_settings', [
            'singleton_key' => KsefSetting::SINGLETON_KEY,
            'name' => 'KSeF',
            'environment' => 'test',
            'automatic_submission' => false,
            'include_order_reference' => true,
            'include_bank_account' => true,
            'include_gtu' => true,
            'include_sale_date' => true,
        ]);
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
            'automatic_submission' => true,
            'send_without_buyer_nip' => true,
            'include_recipient_data' => true,
            'include_buyer_contact_data' => true,
            'include_additional_information' => true,
            'include_order_reference' => false,
            'include_bank_account' => false,
            'include_gtu' => false,
            'include_sale_date' => false,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('ksef_settings', [
            'automatic_submission' => true,
            'send_without_buyer_nip' => true,
            'include_recipient_data' => true,
            'include_buyer_contact_data' => true,
            'include_additional_information' => true,
            'include_order_reference' => false,
            'include_bank_account' => false,
            'include_gtu' => false,
            'include_sale_date' => false,
        ]);
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
            'finalized_at' => now(),
        ]);
        $before = $invoice->only([
            'finalized_at',
            'lock_version',
            'buyer_snapshot',
            'status',
        ]);

        Http::preventStrayRequests();
        $this->put(route('integrations.ksef.update'), $this->payload())
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($before, $invoice->refresh()->only(array_keys($before)));
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
