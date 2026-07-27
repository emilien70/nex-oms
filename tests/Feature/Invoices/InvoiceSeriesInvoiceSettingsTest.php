<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\InvoiceSeries;
use Tests\TestCase;

class InvoiceSeriesInvoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_invoice_settings_and_preserves_system_series(): void
    {
        $this->assertTrue(Schema::hasColumns('invoice_series', [
            'vat_rate_source',
            'default_vat_rate',
            'include_shipping',
            'shipping_vat_mode',
            'default_shipping_vat_rate',
            'skip_zero_price_items',
            'payment_method_source',
            'fixed_payment_method',
            'sale_date_source',
            'payment_due_mode',
            'payment_due_days',
            'unit_price_mode',
            'show_vat_column',
            'show_order_number',
            'show_buyer_signature',
            'show_original_copy',
            'print_template',
            'primary_language',
            'secondary_language',
            'document_title',
            'copies_count',
        ]));

        $this->assertSame(3, InvoiceSeries::query()->where('is_system', true)->count());
        $this->assertDatabaseHas('invoice_series', ['system_key' => 'invoice']);
        $this->assertDatabaseHas('invoice_series', ['system_key' => 'correction']);
        $this->assertDatabaseHas('invoice_series', ['system_key' => 'proforma']);
    }

    public function test_invoice_form_has_all_sections_but_other_types_remain_basic(): void
    {
        $invoice = $this->get(route('invoices.series.form', ['document_type' => 'invoice']));

        $invoice->assertOk();
        foreach ([
            'Dane sprzedawcy',
            'Rachunek bankowy',
            'Wystawienie dokumentu',
            'VAT i pozycje',
            'Płatność i daty',
            'Seria korekt',
            'Informacje',
            'Ustawienia wydruku',
            'Logo',
        ] as $section) {
            $invoice->assertSee($section);
        }

        foreach (['correction', 'proforma'] as $documentType) {
            $this->get(route('invoices.series.form', ['document_type' => $documentType]))
                ->assertOk()
                ->assertDontSee('Dane sprzedawcy')
                ->assertDontSee('VAT i pozycje')
                ->assertDontSee('Płatność i daty')
                ->assertDontSee('Ustawienia wydruku');
        }
    }

    public function test_custom_invoice_series_can_be_created_with_complete_configuration(): void
    {
        $correction = $this->systemSeries(InvoiceSeriesSystemKey::Correction);
        $template = "Numery seryjne zakupionych przedmiotów:\n[uwagi_sprzedawcy]";

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Faktury Firma',
            'seller_name' => '  Firma Testowa  ',
            'seller_tax_id' => ' 123-456-78-90 ',
            'seller_country_code' => 'de',
            'seller_bank_swift' => 'abcddexx',
            'default_correction_series_id' => $correction->id,
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
            'unit_price_mode' => 'net',
            'show_vat_column' => false,
            'show_order_number' => true,
            'show_buyer_signature' => true,
            'show_original_copy' => true,
            'primary_language' => 'pl',
            'secondary_language' => 'en',
            'document_title' => 'Faktura sprzedaży',
            'copies_count' => 2,
            'additional_information_template' => $template,
        ]))
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Faktury Firma')->firstOrFail();
        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);
        $this->assertSame('Firma Testowa', $series->seller_name);
        $this->assertSame('123-456-78-90', $series->seller_tax_id);
        $this->assertSame('DE', $series->seller_country_code);
        $this->assertSame('ABCDDEXX', $series->seller_bank_swift);
        $this->assertSame($correction->id, $series->default_correction_series_id);
        $this->assertSame('23.00', $series->default_vat_rate);
        $this->assertSame('8.00', $series->default_shipping_vat_rate);
        $this->assertSame(14, $series->payment_due_days);
        $this->assertSame(2, $series->copies_count);
        $this->assertSame($template, $series->additional_information_template);
    }

    public function test_system_invoice_business_settings_are_editable_without_weakening_protection(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => 'correction',
            'name' => 'Faktury główne',
            'seller_name' => 'Sprzedawca Główny',
            'document_title' => 'Faktura VAT sprzedaży',
            'is_active' => false,
            'is_system' => false,
            'system_key' => 'correction',
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Invoice, $series->document_type);
        $this->assertSame(InvoiceSeriesSystemKey::Invoice, $series->system_key);
        $this->assertTrue($series->is_system);
        $this->assertTrue($series->is_active);
        $this->assertSame('Sprzedawca Główny', $series->seller_name);
        $this->assertSame('Faktura VAT sprzedaży', $series->document_title);
    }

    public function test_partially_completed_seller_data_does_not_block_save(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Częściowe dane sprzedawcy',
            'seller_name' => 'Tylko nazwa',
            'seller_tax_id' => '',
            'seller_city' => '',
        ]))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect(route('invoices.series.index'));

        $this->assertDatabaseHas('invoice_series', [
            'name' => 'Częściowe dane sprzedawcy',
            'seller_name' => 'Tylko nazwa',
            'seller_tax_id' => null,
        ]);
    }

    public function test_default_correction_series_accepts_only_correction_type(): void
    {
        $correction = $this->systemSeries(InvoiceSeriesSystemKey::Correction);
        $invoice = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $proforma = $this->systemSeries(InvoiceSeriesSystemKey::Proforma);

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Poprawna seria korekt',
            'default_correction_series_id' => $correction->id,
        ]))->assertSessionDoesntHaveErrors();

        foreach ([$invoice, $proforma] as $invalidSeries) {
            $this->from(route('invoices.series.index'))
                ->post(route('invoices.series.store'), $this->validPayload([
                    'name' => 'Niepoprawna relacja '.$invalidSeries->id,
                    'default_correction_series_id' => $invalidSeries->id,
                ]))
                ->assertSessionHasErrors([
                    'default_correction_series_id' => 'Wybrana seria korekt jest nieprawidłowa.',
                ]);
        }
    }

    public function test_vat_rules_are_conditional_and_reject_out_of_range_values(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak stałego VAT',
            'vat_rate_source' => 'fixed',
            'default_vat_rate' => null,
        ]))->assertSessionHasErrors('default_vat_rate');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'VAT z pozycji',
            'vat_rate_source' => 'order_item',
            'default_vat_rate' => null,
        ]))->assertSessionDoesntHaveErrors();

        foreach ([-0.01, 100.01] as $vat) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'VAT poza zakresem '.$vat,
                'vat_rate_source' => 'fixed',
                'default_vat_rate' => $vat,
            ]))->assertSessionHasErrors('default_vat_rate');
        }
    }

    public function test_shipping_vat_is_required_only_for_included_shipping_with_fixed_rate(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak VAT dostawy',
            'include_shipping' => true,
            'shipping_vat_mode' => 'fixed',
            'default_shipping_vat_rate' => null,
        ]))->assertSessionHasErrors('default_shipping_vat_rate');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Najwyższy VAT dostawy',
            'include_shipping' => true,
            'shipping_vat_mode' => 'highest_item',
            'default_shipping_vat_rate' => null,
        ]))->assertSessionDoesntHaveErrors();

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Dostawa wyłączona',
            'include_shipping' => false,
            'shipping_vat_mode' => 'fixed',
            'default_shipping_vat_rate' => null,
        ]))->assertSessionDoesntHaveErrors();
    }

    public function test_payment_method_and_due_date_rules_are_conditional(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak stałej płatności',
            'payment_method_source' => 'fixed',
            'fixed_payment_method' => null,
        ]))->assertSessionHasErrors('fixed_payment_method');

        foreach (['order', 'none'] as $source) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Płatność '.$source,
                'payment_method_source' => $source,
                'fixed_payment_method' => null,
            ]))->assertSessionDoesntHaveErrors();
        }

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Brak dni płatności',
            'payment_due_mode' => 'days_from_issue',
            'payment_due_days' => null,
        ]))->assertSessionHasErrors('payment_due_days');

        foreach ([-1, 366] as $days) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Termin '.$days,
                'payment_due_mode' => 'days_from_issue',
                'payment_due_days' => $days,
            ]))->assertSessionHasErrors('payment_due_days');
        }
    }

    public function test_print_settings_validate_copy_count_and_language_pair(): void
    {
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

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Język kupującego',
            'primary_language' => 'buyer_country',
            'secondary_language' => 'pl',
        ]))->assertSessionDoesntHaveErrors();
    }

    public function test_information_token_is_stored_literally_without_serial_number_schema(): void
    {
        $template = "Informacje:\n[uwagi_sprzedawcy]";

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Seria z informacjami',
            'additional_information_template' => $template,
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Seria z informacjami')->firstOrFail();
        $this->assertSame($template, $series->additional_information_template);
        $this->assertFalse(Schema::hasColumn('orders', 'serial_numbers_text'));
        $this->assertFalse(Schema::hasTable('serial_numbers'));
    }

    public function test_valid_logo_is_stored_on_private_local_disk(): void
    {
        Storage::fake('local');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Seria z logo',
            'logo' => $this->fakePng('logo.png'),
        ]))->assertSessionDoesntHaveErrors();

        $series = InvoiceSeries::query()->where('name', 'Seria z logo')->firstOrFail();
        $this->assertNotNull($series->logo_path);
        $this->assertStringStartsWith("invoice-series/logos/{$series->id}/", $series->logo_path);
        Storage::disk('local')->assertExists($series->logo_path);
    }

    public function test_invalid_and_oversized_logos_are_rejected(): void
    {
        Storage::fake('local');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Logo PDF',
            'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('logo');

        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Logo za duże',
            'logo' => $this->fakePng('logo.png', 2049),
        ]))->assertSessionHasErrors('logo');
    }

    public function test_replacing_and_removing_logo_manages_only_owned_private_file(): void
    {
        Storage::fake('local');
        $series = $this->createCustomInvoice('Logo do zmiany');
        $oldPath = "invoice-series/logos/{$series->id}/old.png";
        Storage::disk('local')->put($oldPath, 'old');
        $series->update(['logo_path' => $oldPath]);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'name' => $series->name,
            'logo' => $this->fakePng('new.png'),
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $series->refresh();
        $newPath = $series->logo_path;
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

    public function test_number_format_validation_from_previous_stage_is_unchanged(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Niepoprawny format',
            'number_format' => 'BL /%Y',
        ]))->assertSessionHasErrors([
            'number_format' => 'Format numeracji musi zawierać token %N.',
        ]);

        foreach (['%N/%Y', '%NN/%Y', '%NNN/%Y'] as $index => $format) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Format 1C.2 '.$index,
                'number_format' => $format,
            ]))->assertSessionDoesntHaveErrors();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'document_type' => InvoiceDocumentType::Invoice->value,
            'name' => 'Seria ustawień '.uniqid(),
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly->value,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
            'vat_rate_source' => 'order_item',
            'default_vat_rate' => null,
            'include_shipping' => true,
            'shipping_vat_mode' => 'highest_item',
            'default_shipping_vat_rate' => null,
            'skip_zero_price_items' => false,
            'payment_method_source' => 'order',
            'fixed_payment_method' => null,
            'sale_date_source' => 'payment_or_issue',
            'payment_due_mode' => 'none',
            'payment_due_days' => null,
            'unit_price_mode' => 'gross',
            'show_vat_column' => true,
            'show_order_number' => false,
            'show_buyer_signature' => false,
            'show_original_copy' => false,
            'print_template' => 'standard',
            'primary_language' => 'buyer_country',
            'secondary_language' => null,
            'document_title' => 'Faktura VAT',
            'copies_count' => 1,
            'remove_logo' => false,
            'form_mode' => 'create',
            'editing_series_id' => null,
        ], $overrides);
    }

    private function systemSeries(InvoiceSeriesSystemKey $key): InvoiceSeries
    {
        return InvoiceSeries::query()->where('system_key', $key->value)->firstOrFail();
    }

    private function createCustomInvoice(string $name): InvoiceSeries
    {
        return InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => $name,
            'number_format' => 'TEST %N/%Y',
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
