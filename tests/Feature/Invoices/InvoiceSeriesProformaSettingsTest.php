<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Enums\InvoiceSaleDateSource;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Enums\InvoiceUnitPriceMode;
use Modules\Invoices\Models\InvoiceSeries;
use Tests\TestCase;

class InvoiceSeriesProformaSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_proforma_form_has_commercial_sections_without_correction_configuration(): void
    {
        $response = $this->get(route('invoices.series.form', ['document_type' => 'proforma']));

        $response->assertOk();
        $response->assertSee('name="print_header"', false);
        foreach ([
            'Dane sprzedawcy',
            'Rachunek bankowy',
            'Wystawienie dokumentu',
            'VAT i pozycje',
            'Płatność i daty',
            'Informacje',
            'Ustawienia wydruku',
            'Logo',
        ] as $section) {
            $response->assertSee($section);
        }

        $response->assertSee('name="show_payment_identifier"', false);
        foreach ([
            'default_correction_series_id',
            'default_correction_reason',
            'correction_sale_date_source',
            'correction_issuer_source',
            'correction_payment_method_source',
            'show_correction_item_sequence',
            'show_return_id_in_header',
        ] as $forbiddenField) {
            $response->assertDontSee($forbiddenField, false);
        }
    }

    public function test_proforma_edit_form_restores_the_saved_print_header(): void
    {
        $series = $this->systemProforma();
        $series->update(['print_header' => 'Multi-Click Pro Forma']);

        $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertSee('name="print_header"', false)
            ->assertSee('value="Multi-Click Pro Forma"', false);
    }

    public function test_invoice_and_correction_forms_keep_their_existing_sections(): void
    {
        $this->get(route('invoices.series.form', ['document_type' => 'invoice']))
            ->assertOk()
            ->assertSee('Dane sprzedawcy')
            ->assertSee('Seria korekt')
            ->assertSee('Logo')
            ->assertDontSee('name="show_payment_identifier"', false);

        $this->get(route('invoices.series.form', ['document_type' => 'correction']))
            ->assertOk()
            ->assertSee('Ustawienia korekty')
            ->assertSee('Wystawiający i płatność')
            ->assertDontSee('Dane sprzedawcy')
            ->assertDontSee('Logo');
    }

    public function test_new_proforma_uses_safe_defaults_when_extended_fields_are_omitted(): void
    {
        $this->post(route('invoices.series.store'), $this->basePayload([
            'name' => 'Domyślna pro forma',
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Domyślna pro forma')->firstOrFail();
        $this->assertFalse($series->include_shipping);
        $this->assertSame(InvoicePaymentMethodSource::None, $series->payment_method_source);
        $this->assertSame(InvoiceSaleDateSource::PaymentOrIssue, $series->sale_date_source);
        $this->assertSame(InvoicePaymentDueMode::None, $series->payment_due_mode);
        $this->assertSame(InvoiceUnitPriceMode::Net, $series->unit_price_mode);
        $this->assertTrue($series->show_vat_column);
        $this->assertFalse($series->show_payment_identifier);
        $this->assertSame('Faktura pro forma', $series->document_title);
        $this->assertSame(1, $series->copies_count);
        $this->assertSame('PL', $series->seller_country_code);
        $this->assertNull($series->default_correction_series_id);
    }

    public function test_custom_proforma_can_be_created_with_complete_configuration(): void
    {
        $template = "Numery seryjne zakupionych przedmiotów:\n[uwagi_sprzedawcy]";

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma eksportowa',
            'seller_name' => '  Firma Testowa  ',
            'seller_tax_id' => ' 123-456-78-90 ',
            'seller_country_code' => 'de',
            'seller_bank_swift' => 'abcddexx',
            'vat_rate_source' => 'fixed',
            'default_vat_rate' => '23.00',
            'include_shipping' => true,
            'shipping_vat_mode' => 'fixed',
            'default_shipping_vat_rate' => '8.00',
            'skip_zero_price_items' => true,
            'payment_method_source' => 'fixed',
            'fixed_payment_method' => 'Przelew bankowy',
            'sale_date_source' => 'order_date',
            'payment_due_mode' => 'days_from_issue',
            'payment_due_days' => 14,
            'show_payment_identifier' => true,
            'show_order_number' => true,
            'primary_language' => 'pl',
            'secondary_language' => 'en',
            'document_title' => 'Pro forma eksportowa',
            'print_header' => 'Multi-Click Pro Forma',
            'copies_count' => 2,
            'additional_information_template' => $template,
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Pro forma eksportowa')->firstOrFail();
        $this->assertSame(InvoiceDocumentType::Proforma, $series->document_type);
        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);
        $this->assertSame('Firma Testowa', $series->seller_name);
        $this->assertSame('DE', $series->seller_country_code);
        $this->assertSame('ABCDDEXX', $series->seller_bank_swift);
        $this->assertSame('23.00', $series->default_vat_rate);
        $this->assertSame('8.00', $series->default_shipping_vat_rate);
        $this->assertTrue($series->show_payment_identifier);
        $this->assertSame('Multi-Click Pro Forma', $series->print_header);
        $this->assertSame($template, $series->additional_information_template);
        $this->assertNull($series->default_correction_series_id);
    }

    public function test_system_proforma_business_settings_are_editable_without_weakening_protection(): void
    {
        $series = $this->systemProforma();
        $correction = $this->systemSeries(InvoiceSeriesSystemKey::Correction);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => 'invoice',
            'name' => 'Pro formy główne',
            'seller_name' => 'Sprzedawca główny',
            'show_payment_identifier' => true,
            'default_correction_series_id' => $correction->id,
            'is_active' => false,
            'is_system' => false,
            'system_key' => 'invoice',
            'is_default' => true,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Proforma, $series->document_type);
        $this->assertSame(InvoiceSeriesSystemKey::Proforma, $series->system_key);
        $this->assertTrue($series->is_system);
        $this->assertTrue($series->is_active);
        $this->assertSame('Sprzedawca główny', $series->seller_name);
        $this->assertTrue($series->show_payment_identifier);
        $this->assertNull($series->default_correction_series_id);
    }

    public function test_partially_completed_seller_data_does_not_block_proforma_save(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Częściowy sprzedawca pro formy',
            'seller_name' => 'Tylko nazwa',
            'seller_tax_id' => '',
            'seller_city' => '',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('invoice_series', [
            'name' => 'Częściowy sprzedawca pro formy',
            'seller_name' => 'Tylko nazwa',
            'seller_tax_id' => null,
            'seller_city' => null,
        ]);
    }

    public function test_proforma_vat_rules_are_conditional_and_bounded(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma bez stałego VAT',
            'vat_rate_source' => 'fixed',
            'default_vat_rate' => null,
        ]))->assertSessionHasErrors('default_vat_rate');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma VAT z pozycji',
            'vat_rate_source' => 'order_item',
            'default_vat_rate' => null,
        ]))->assertSessionDoesntHaveErrors();

        foreach ([-0.01, 100.01] as $vat) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Pro forma VAT poza zakresem '.$vat,
                'vat_rate_source' => 'fixed',
                'default_vat_rate' => $vat,
            ]))->assertSessionHasErrors('default_vat_rate');
        }
    }

    public function test_proforma_shipping_vat_is_required_only_for_enabled_fixed_shipping(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma brak VAT dostawy',
            'include_shipping' => true,
            'shipping_vat_mode' => 'fixed',
            'default_shipping_vat_rate' => null,
        ]))->assertSessionHasErrors('default_shipping_vat_rate');

        foreach ([
            ['include_shipping' => false, 'shipping_vat_mode' => 'fixed'],
            ['include_shipping' => true, 'shipping_vat_mode' => 'highest_item'],
        ] as $index => $settings) {
            $this->post(route('invoices.series.store'), $this->validPayload(array_merge([
                'name' => 'Pro forma dostawa bez stałej '.$index,
                'default_shipping_vat_rate' => null,
            ], $settings)))->assertSessionDoesntHaveErrors();
        }
    }

    public function test_proforma_payment_and_due_date_rules_are_conditional(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma bez stałej płatności',
            'payment_method_source' => 'fixed',
            'fixed_payment_method' => null,
        ]))->assertSessionHasErrors('fixed_payment_method');

        foreach (['order', 'none'] as $source) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Pro forma płatność '.$source,
                'payment_method_source' => $source,
                'fixed_payment_method' => null,
            ]))->assertSessionDoesntHaveErrors();
        }

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma bez dni płatności',
            'payment_due_mode' => 'days_from_issue',
            'payment_due_days' => null,
        ]))->assertSessionHasErrors('payment_due_days');

        foreach ([-1, 366] as $days) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Pro forma termin '.$days,
                'payment_due_mode' => 'days_from_issue',
                'payment_due_days' => $days,
            ]))->assertSessionHasErrors('payment_due_days');
        }
    }

    public function test_proforma_print_settings_are_validated_and_booleans_are_saved(): void
    {
        foreach ([0, 11] as $copies) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Pro forma kopie '.$copies,
                'copies_count' => $copies,
            ]))->assertSessionHasErrors('copies_count');
        }

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma te same języki',
            'primary_language' => 'pl',
            'secondary_language' => 'pl',
        ]))->assertSessionHasErrors('secondary_language');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma boolean',
            'show_payment_identifier' => '1',
            'show_order_number' => '0',
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Pro forma boolean')->firstOrFail();
        $this->assertTrue($series->show_payment_identifier);
        $this->assertFalse($series->show_order_number);
    }

    public function test_information_token_is_stored_literally_without_serial_number_schema(): void
    {
        $template = "Informacje:\n[uwagi_sprzedawcy]";

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma z informacjami',
            'additional_information_template' => $template,
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Pro forma z informacjami')->firstOrFail();
        $this->assertSame($template, $series->additional_information_template);
        $this->assertFalse(Schema::hasColumn('orders', 'serial_numbers_text'));
        $this->assertFalse(Schema::hasTable('serial_numbers'));
    }

    public function test_valid_proforma_logo_is_stored_on_private_local_disk(): void
    {
        Storage::fake('local');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma z logo',
            'logo' => $this->fakePng('logo.png'),
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Pro forma z logo')->firstOrFail();
        $this->assertNotNull($series->logo_path);
        $this->assertStringStartsWith("invoice-series/logos/{$series->id}/", $series->logo_path);
        Storage::disk('local')->assertExists($series->logo_path);
    }

    public function test_invalid_and_oversized_proforma_logos_are_rejected(): void
    {
        Storage::fake('local');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma logo PDF',
            'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('logo');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma logo za duże',
            'logo' => $this->fakePng('logo.png', 2049),
        ]))->assertSessionHasErrors('logo');
    }

    public function test_replacing_and_removing_proforma_logo_deletes_only_owned_file(): void
    {
        Storage::fake('local');
        $series = $this->createCustomProforma('Pro forma logo do zmiany');
        $oldPath = "invoice-series/logos/{$series->id}/old.png";
        Storage::disk('local')->put($oldPath, 'old');
        $series->update(['logo_path' => $oldPath]);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'name' => $series->name,
            'logo' => $this->fakePng('new.png'),
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $newPath = $series->refresh()->logo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'name' => $series->name,
            'remove_logo' => true,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertNull($series->refresh()->logo_path);
        Storage::disk('local')->assertMissing($newPath);
    }

    public function test_proforma_rejects_correction_series_and_invoice_to_proforma_clears_existing_relation(): void
    {
        $correction = $this->systemSeries(InvoiceSeriesSystemKey::Correction);

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma z próbą relacji',
            'default_correction_series_id' => $correction->id,
        ]))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('invoice_series', [
            'name' => 'Pro forma z próbą relacji',
            'default_correction_series_id' => null,
        ]);

        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Faktura zmieniana na pro formę',
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
            'default_correction_series_id' => $correction->id,
        ]);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'name' => $series->name,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Proforma, $series->document_type);
        $this->assertNull($series->default_correction_series_id);
        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);
    }

    public function test_proforma_can_change_to_invoice_and_select_correction_series(): void
    {
        $series = $this->createCustomProforma('Pro forma zmieniana na fakturę');
        $correction = $this->systemSeries(InvoiceSeriesSystemKey::Correction);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => 'invoice',
            'name' => $series->name,
            'include_shipping' => true,
            'payment_method_source' => 'order',
            'unit_price_mode' => 'gross',
            'document_title' => 'Faktura VAT',
            'default_correction_series_id' => $correction->id,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Invoice, $series->document_type);
        $this->assertSame($correction->id, $series->default_correction_series_id);
    }

    public function test_number_format_validation_is_unchanged_for_proforma(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Pro forma błędny format',
            'number_format' => 'BLPF /%Y',
        ]))->assertSessionHasErrors([
            'number_format' => 'Format numeracji musi zawierać token %N.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_replace([
            'document_type' => InvoiceDocumentType::Proforma->value,
            'name' => 'Seria Pro Forma '.uniqid(),
            'number_format' => 'BLPF %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly->value,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
            'form_mode' => 'create',
            'editing_series_id' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace($this->basePayload(), [
            'vat_rate_source' => 'order_item',
            'default_vat_rate' => null,
            'include_shipping' => false,
            'shipping_vat_mode' => 'highest_item',
            'default_shipping_vat_rate' => null,
            'skip_zero_price_items' => false,
            'payment_method_source' => 'none',
            'fixed_payment_method' => null,
            'sale_date_source' => 'payment_or_issue',
            'payment_due_mode' => 'none',
            'payment_due_days' => null,
            'unit_price_mode' => 'net',
            'show_vat_column' => true,
            'show_order_number' => false,
            'show_payment_identifier' => false,
            'show_buyer_signature' => false,
            'show_original_copy' => false,
            'print_template' => 'standard',
            'primary_language' => 'buyer_country',
            'secondary_language' => null,
            'document_title' => 'Faktura pro forma',
            'print_header' => null,
            'copies_count' => 1,
            'remove_logo' => false,
        ], $overrides);
    }

    private function systemProforma(): InvoiceSeries
    {
        return $this->systemSeries(InvoiceSeriesSystemKey::Proforma);
    }

    private function systemSeries(InvoiceSeriesSystemKey $key): InvoiceSeries
    {
        return InvoiceSeries::query()->where('system_key', $key->value)->firstOrFail();
    }

    private function createCustomProforma(string $name): InvoiceSeries
    {
        return InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Proforma,
            'name' => $name,
            'number_format' => 'BLPF %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
        ]);
    }

    private function fakePng(string $name, ?int $sizeInKilobytes = null): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z2S8AAAAASUVORK5CYII=',
            true,
        );
        $content = $png === false ? '' : $png;

        if ($sizeInKilobytes !== null) {
            $content .= str_repeat("\0", ($sizeInKilobytes * 1024) - strlen($content));
        }

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
