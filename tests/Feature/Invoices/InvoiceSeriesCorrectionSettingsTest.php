<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\InvoiceSeries;
use Tests\TestCase;

class InvoiceSeriesCorrectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_correction_configuration_and_preserves_system_series(): void
    {
        $this->assertTrue(Schema::hasColumns('invoice_series', [
            'default_correction_reason',
            'correction_sale_date_source',
            'correction_issuer_source',
            'correction_payment_method_source',
            'show_correction_item_sequence',
            'show_return_id_in_header',
            'show_payment_identifier',
        ]));

        $series = $this->systemCorrection();
        $this->assertSame(InvoiceDocumentType::Correction, $series->document_type);
        $this->assertSame(InvoiceSeriesSystemKey::Correction, $series->system_key);
        $this->assertTrue($series->is_system);
        $this->assertTrue($series->is_active);
        $this->assertSame('Faktura korygująca', $series->document_title);
        $this->assertSame(CorrectionSaleDateSource::SourceInvoice, $series->correction_sale_date_source);
        $this->assertSame(CorrectionIssuerSource::SourceInvoice, $series->correction_issuer_source);
        $this->assertSame(CorrectionPaymentMethodSource::SourceInvoice, $series->correction_payment_method_source);
        $this->assertFalse($series->show_correction_item_sequence);
        $this->assertFalse($series->show_return_id_in_header);
        $this->assertFalse($series->show_payment_identifier);
    }

    public function test_migration_replaces_generic_title_but_preserves_custom_correction_title(): void
    {
        $migration = require database_path('migrations/2026_07_28_000000_add_correction_configuration_to_invoice_series_table.php');
        $migration->down();

        $systemId = $this->systemCorrection()->id;
        $customId = DB::table('invoice_series')->insertGetId([
            'document_type' => 'correction',
            'name' => 'Korekty specjalne',
            'number_format' => 'KS %N/%Y',
            'reset_period' => 'yearly',
            'fiscal_year_start_month' => 1,
            'is_active' => false,
            'is_system' => false,
            'system_key' => null,
            'default_currency' => 'PLN',
            'document_title' => 'Korekta handlowa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_series')->where('id', $systemId)->update(['document_title' => 'Faktura VAT']);

        $migration->up();

        $this->assertSame('Faktura korygująca', DB::table('invoice_series')->where('id', $systemId)->value('document_title'));
        $this->assertSame('Korekta handlowa', DB::table('invoice_series')->where('id', $customId)->value('document_title'));
        $this->assertSame('correction', DB::table('invoice_series')->where('id', $customId)->value('document_type'));
    }

    public function test_correction_form_has_required_sections_without_invoice_seller_configuration(): void
    {
        $response = $this->get(route('invoices.series.form', ['document_type' => 'correction']));

        $response->assertOk();
        $response->assertSee('name="print_header"', false);
        foreach ([
            'Ustawienia korekty',
            'Wystawiający i płatność',
            'Informacje',
            'Pozycje i nagłówek',
            'Ustawienia wydruku',
        ] as $section) {
            $response->assertSee($section);
        }

        foreach ([
            'Dane sprzedawcy',
            'Rachunek bankowy',
            'Logo',
            'default_correction_series_id',
            'include_shipping',
            'shipping_vat_mode',
        ] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }

    public function test_correction_print_header_is_saved_and_restored_in_edit_form(): void
    {
        $series = $this->systemCorrection();

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'print_header' => 'Multi-Click Korekta',
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame('Multi-Click Korekta', $series->refresh()->print_header);

        $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertSee('name="print_header"', false)
            ->assertSee('value="Multi-Click Korekta"', false);
    }

    public function test_invoice_and_proforma_forms_remain_extended_without_correction_settings(): void
    {
        $this->get(route('invoices.series.form', ['document_type' => 'invoice']))
            ->assertOk()
            ->assertSee('Dane sprzedawcy')
            ->assertSee('VAT i pozycje');

        $this->get(route('invoices.series.form', ['document_type' => 'proforma']))
            ->assertOk()
            ->assertDontSee('Ustawienia korekty')
            ->assertSee('Dane sprzedawcy')
            ->assertSee('Ustawienia wydruku');
    }

    public function test_custom_correction_series_can_be_created_with_all_settings(): void
    {
        $template = "Uwagi:\n[uwagi_sprzedawcy]";

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Korekty eksportowe',
            'default_correction_reason' => '  Zwrot towaru  ',
            'correction_sale_date_source' => 'issue_date',
            'correction_issuer_source' => 'series',
            'issuer_name' => '  Jan Kowalski  ',
            'correction_payment_method_source' => 'fixed',
            'fixed_payment_method' => '  Przelew  ',
            'show_correction_item_sequence' => true,
            'show_return_id_in_header' => true,
            'show_payment_identifier' => true,
            'unit_price_mode' => 'net',
            'show_vat_column' => false,
            'show_order_number' => true,
            'show_buyer_signature' => true,
            'show_original_copy' => true,
            'copies_count' => 2,
            'primary_language' => 'pl',
            'secondary_language' => 'en',
            'print_header' => 'Multi-Click Korekta',
            'additional_information_template' => $template,
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Korekty eksportowe')->firstOrFail();
        $this->assertSame('Zwrot towaru', $series->default_correction_reason);
        $this->assertSame(CorrectionSaleDateSource::IssueDate, $series->correction_sale_date_source);
        $this->assertSame(CorrectionIssuerSource::Series, $series->correction_issuer_source);
        $this->assertSame('Jan Kowalski', $series->issuer_name);
        $this->assertSame(CorrectionPaymentMethodSource::Fixed, $series->correction_payment_method_source);
        $this->assertSame('Przelew', $series->fixed_payment_method);
        $this->assertTrue($series->show_correction_item_sequence);
        $this->assertTrue($series->show_return_id_in_header);
        $this->assertTrue($series->show_payment_identifier);
        $this->assertSame('Multi-Click Korekta', $series->print_header);
        $this->assertSame($template, $series->additional_information_template);
    }

    public function test_system_correction_business_settings_are_editable_without_weakening_protection(): void
    {
        $series = $this->systemCorrection();
        $series->forceFill([
            'seller_name' => 'Historyczny sprzedawca',
            'seller_tax_id' => '1234567890',
            'logo_path' => 'invoice-series/logos/system/logo.png',
        ])->save();

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => 'invoice',
            'name' => 'Korekty główne',
            'default_correction_reason' => 'Reklamacja',
            'seller_name' => 'Podmieniony sprzedawca',
            'seller_tax_id' => '0000000000',
            'logo' => UploadedFile::fake()->create('forged.png', 1, 'image/png'),
            'remove_logo' => true,
            'is_active' => false,
            'is_system' => false,
            'system_key' => 'invoice',
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Correction, $series->document_type);
        $this->assertSame(InvoiceSeriesSystemKey::Correction, $series->system_key);
        $this->assertTrue($series->is_system);
        $this->assertTrue($series->is_active);
        $this->assertSame('Reklamacja', $series->default_correction_reason);
        $this->assertSame('Historyczny sprzedawca', $series->seller_name);
        $this->assertSame('1234567890', $series->seller_tax_id);
        $this->assertSame('invoice-series/logos/system/logo.png', $series->logo_path);
    }

    public function test_reason_is_optional_trimmed_and_limited(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Powód pusty',
            'default_correction_reason' => '',
        ]))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('invoice_series', ['name' => 'Powód pusty', 'default_correction_reason' => null]);

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Powód przycięty',
            'default_correction_reason' => '  Zmiana ceny  ',
        ]))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('invoice_series', ['name' => 'Powód przycięty', 'default_correction_reason' => 'Zmiana ceny']);

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Powód za długi',
            'default_correction_reason' => str_repeat('a', 1001),
        ]))->assertSessionHasErrors('default_correction_reason');
    }

    public function test_closed_correction_sources_are_validated(): void
    {
        foreach (['correction_sale_date_source', 'correction_issuer_source', 'correction_payment_method_source'] as $field) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Błędne źródło '.$field,
                $field => 'invalid',
            ]))->assertSessionHasErrors($field);
        }

        foreach (CorrectionSaleDateSource::cases() as $index => $source) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Data sprzedaży '.$index,
                'correction_sale_date_source' => $source->value,
            ]))->assertSessionDoesntHaveErrors();
        }
    }

    public function test_issuer_name_is_required_only_for_series_source(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak wystawiającego',
            'correction_issuer_source' => 'series',
            'issuer_name' => null,
        ]))->assertSessionHasErrors('issuer_name');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Wystawiający ze źródła',
            'correction_issuer_source' => 'source_invoice',
            'issuer_name' => null,
        ]))->assertSessionDoesntHaveErrors();
    }

    public function test_fixed_payment_method_is_required_only_for_fixed_source(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak stałej płatności',
            'correction_payment_method_source' => 'fixed',
            'fixed_payment_method' => null,
        ]))->assertSessionHasErrors('fixed_payment_method');

        foreach (['source_invoice', 'none'] as $source) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Płatność '.$source,
                'correction_payment_method_source' => $source,
                'fixed_payment_method' => null,
            ]))->assertSessionDoesntHaveErrors();
        }
    }

    public function test_boolean_print_settings_are_normalized_and_saved(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Ustawienia boolean',
            'show_correction_item_sequence' => '1',
            'show_return_id_in_header' => '0',
            'show_payment_identifier' => '1',
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Ustawienia boolean')->firstOrFail();
        $this->assertTrue($series->show_correction_item_sequence);
        $this->assertFalse($series->show_return_id_in_header);
        $this->assertTrue($series->show_payment_identifier);
    }

    public function test_print_configuration_validation_is_enforced(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak tytułu',
            'document_title' => '   ',
        ]))->assertSessionHasErrors('document_title');

        foreach ([0, 11] as $copies) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Kopie '.$copies,
                'copies_count' => $copies,
            ]))->assertSessionHasErrors('copies_count');
        }

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Te same języki',
            'primary_language' => 'pl',
            'secondary_language' => 'pl',
        ]))->assertSessionHasErrors('secondary_language');
    }

    public function test_information_token_is_stored_literally_without_serial_number_schema(): void
    {
        $template = "Numery seryjne:\n[uwagi_sprzedawcy]";

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Korekty z informacjami',
            'additional_information_template' => $template,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('invoice_series', [
            'name' => 'Korekty z informacjami',
            'additional_information_template' => $template,
        ]);
        $this->assertFalse(Schema::hasColumn('orders', 'serial_numbers_text'));
        $this->assertFalse(Schema::hasTable('serial_numbers'));
    }

    public function test_switching_custom_series_to_correction_applies_safe_defaults(): void
    {
        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Proforma,
            'name' => 'Seria do zmiany',
            'number_format' => 'TMP %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
        ]);

        $payload = $this->validPayload([
            'name' => $series->name,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]);
        unset(
            $payload['correction_sale_date_source'],
            $payload['correction_issuer_source'],
            $payload['correction_payment_method_source'],
            $payload['document_title'],
        );

        $this->patch(route('invoices.series.update', $series), $payload)
            ->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Correction, $series->document_type);
        $this->assertSame(CorrectionSaleDateSource::SourceInvoice, $series->correction_sale_date_source);
        $this->assertSame(CorrectionIssuerSource::SourceInvoice, $series->correction_issuer_source);
        $this->assertSame(CorrectionPaymentMethodSource::SourceInvoice, $series->correction_payment_method_source);
        $this->assertSame('Faktura korygująca', $series->document_title);
    }

    public function test_switching_from_correction_preserves_inactive_data_without_showing_it(): void
    {
        $series = $this->createCustomCorrection('Korekta do zmiany');
        $series->update(['default_correction_reason' => 'Zachowaj mnie']);

        $this->patch(route('invoices.series.update', $series), [
            'document_type' => 'proforma',
            'name' => $series->name,
            'number_format' => 'PF %N/%Y',
            'reset_period' => 'yearly',
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ])->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Proforma, $series->document_type);
        $this->assertSame('Zachowaj mnie', $series->default_correction_reason);
        $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertDontSee('default_correction_reason', false);
    }

    public function test_number_format_validation_is_unchanged(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Błędny format korekty',
            'number_format' => 'BLK /%Y',
        ]))->assertSessionHasErrors([
            'number_format' => 'Format numeracji musi zawierać token %N.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'document_type' => InvoiceDocumentType::Correction->value,
            'name' => 'Seria korekt '.uniqid(),
            'number_format' => 'BLK %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly->value,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
            'default_correction_reason' => null,
            'correction_sale_date_source' => 'source_invoice',
            'correction_issuer_source' => 'source_invoice',
            'issuer_name' => null,
            'correction_payment_method_source' => 'source_invoice',
            'fixed_payment_method' => null,
            'show_correction_item_sequence' => false,
            'show_return_id_in_header' => false,
            'show_payment_identifier' => false,
            'document_title' => 'Faktura korygująca',
            'print_header' => null,
            'print_template' => 'standard',
            'primary_language' => 'buyer_country',
            'secondary_language' => null,
            'unit_price_mode' => 'gross',
            'show_vat_column' => true,
            'show_order_number' => false,
            'show_buyer_signature' => false,
            'show_original_copy' => false,
            'copies_count' => 1,
            'additional_information_template' => null,
            'form_mode' => 'create',
            'editing_series_id' => null,
        ], $overrides);
    }

    private function systemCorrection(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction->value)
            ->firstOrFail();
    }

    private function createCustomCorrection(string $name): InvoiceSeries
    {
        return InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Correction,
            'name' => $name,
            'number_format' => 'BLK %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
        ]);
    }
}
