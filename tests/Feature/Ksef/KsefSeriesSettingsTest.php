<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Models\KsefSeriesSetting;
use Tests\TestCase;

class KsefSeriesSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_tab_lists_only_active_invoice_and_correction_series(): void
    {
        $invoiceA = $this->series('Faktury A', InvoiceDocumentType::Invoice);
        $invoiceB = $this->series('Faktury B', InvoiceDocumentType::Invoice);
        $correction = $this->series('Korekty C', InvoiceDocumentType::Correction);
        $proforma = $this->series('Pro formy D', InvoiceDocumentType::Proforma);
        $inactive = $this->series('Faktury nieaktywne', InvoiceDocumentType::Invoice, false);

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'series']));

        $response
            ->assertOk()
            ->assertSeeText($invoiceA->name)
            ->assertSeeText($invoiceB->name)
            ->assertSeeText($correction->name)
            ->assertDontSeeText($proforma->name)
            ->assertDontSeeText($inactive->name)
            ->assertSeeText('Faktura VAT')
            ->assertSeeText('Korekta')
            ->assertSee('name="series_ids[]"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_series_mapping_can_be_enabled_and_disabled_without_modifying_series(): void
    {
        $invoice = $this->series('Faktury KSeF', InvoiceDocumentType::Invoice);
        $correction = $this->series('Korekty KSeF', InvoiceDocumentType::Correction);
        $invoiceBefore = $invoice->fresh()->getAttributes();

        Http::preventStrayRequests();
        $this->put(route('integrations.ksef.series.update'), [
            'series_ids' => [(string) $invoice->getKey(), (string) $correction->getKey()],
        ])->assertRedirect(route('integrations.ksef.edit', ['tab' => 'series']));

        $this->assertDatabaseHas('ksef_series_settings', [
            'invoice_series_id' => $invoice->getKey(),
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('ksef_series_settings', [
            'invoice_series_id' => $correction->getKey(),
            'is_enabled' => true,
        ]);

        $this->put(route('integrations.ksef.series.update'), [
            'series_ids' => [$correction->getKey()],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('ksef_series_settings', [
            'invoice_series_id' => $invoice->getKey(),
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('ksef_series_settings', [
            'invoice_series_id' => $correction->getKey(),
            'is_enabled' => true,
        ]);
        $this->assertSame($invoiceBefore, $invoice->refresh()->getAttributes());
    }

    public function test_crafted_proforma_mapping_rejects_the_entire_save(): void
    {
        $invoice = $this->series('Faktury poprawne', InvoiceDocumentType::Invoice);
        $correction = $this->series('Korekty bez zmian', InvoiceDocumentType::Correction);
        $proforma = $this->series('Pro forma niedozwolona', InvoiceDocumentType::Proforma);

        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $invoice->getKey(),
            'is_enabled' => true,
        ]);

        $this->from(route('integrations.ksef.edit', ['tab' => 'series']))
            ->put(route('integrations.ksef.series.update'), [
                'series_ids' => [$correction->getKey(), $proforma->getKey()],
            ])
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'series']))
            ->assertSessionHasErrors('series_ids.1');

        $this->assertDatabaseHas('ksef_series_settings', [
            'invoice_series_id' => $invoice->getKey(),
            'is_enabled' => true,
        ]);
        $this->assertDatabaseMissing('ksef_series_settings', [
            'invoice_series_id' => $correction->getKey(),
        ]);
        $this->assertDatabaseMissing('ksef_series_settings', [
            'invoice_series_id' => $proforma->getKey(),
        ]);
    }

    public function test_empty_selection_disables_all_supported_series(): void
    {
        $invoice = $this->series('Faktury do wyłączenia', InvoiceDocumentType::Invoice);
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $invoice->getKey(),
            'is_enabled' => true,
        ]);

        $this->put(route('integrations.ksef.series.update'), [])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('ksef_series_settings', [
            'invoice_series_id' => $invoice->getKey(),
            'is_enabled' => false,
        ]);
    }

    private function series(
        string $name,
        InvoiceDocumentType $documentType,
        bool $active = true,
    ): InvoiceSeries {
        return InvoiceSeries::query()->create([
            'document_type' => $documentType,
            'name' => $name,
            'number_format' => strtoupper(substr(md5($name), 0, 6)).'/%N/%Y',
            'is_active' => $active,
        ]);
    }
}
