<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Tests\TestCase;

class InvoiceSeriesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_list_is_available_and_shows_system_series_with_polish_labels(): void
    {
        $response = $this->get(route('invoices.series.index'));

        $response
            ->assertOk()
            ->assertSee('Serie numeracji')
            ->assertSee('Faktury')
            ->assertSee('Korekty')
            ->assertSee('Faktury Pro-Forma')
            ->assertSee('Faktura')
            ->assertSee('Korekta')
            ->assertSee('Pro forma');
    }

    public function test_each_system_series_has_a_non_interactive_empty_star(): void
    {
        $response = $this->get(route('invoices.series.index'));
        $content = $response->getContent();

        $this->assertSame(3, substr_count($content, 'data-role="system-series-marker"'));
        $this->assertSame(3, substr_count($content, 'bi bi-star'));
        $this->assertStringNotContainsString('bi-star-fill', $content);
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:a|button|form)[^>]*data-role="system-series-marker"/i',
            $content,
        );
    }

    public function test_custom_series_has_no_system_marker(): void
    {
        $custom = $this->createCustomSeries('Seria własna');

        $response = $this->get(route('invoices.series.index'));

        $response
            ->assertOk()
            ->assertSee('data-series-row="'.$custom->id.'"', false)
            ->assertDontSee(
                'data-role="system-series-marker" data-series-id="'.$custom->id.'"',
                false,
            );
    }

    public function test_system_series_has_no_active_state_form(): void
    {
        $system = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        $response = $this->get(route('invoices.series.index'));

        $response
            ->assertOk()
            ->assertSee('data-role="system-active-indicator"', false)
            ->assertDontSee(
                'data-role="series-active-form" data-series-id="'.$system->id.'"',
                false,
            );
    }

    public function test_system_series_cannot_be_hidden_by_direct_request(): void
    {
        $system = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        $response = $this->patch(route('invoices.series.active', $system), [
            'is_active' => false,
        ]);

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors([
                'series' => 'Seria systemowa jest zawsze aktywna i nie może zostać ukryta.',
            ]);
        $this->assertTrue($system->refresh()->is_active);
    }

    public function test_active_custom_series_can_be_hidden(): void
    {
        $series = $this->createCustomSeries('Seria aktywna', InvoiceDocumentType::Invoice, true);

        $response = $this->patch(route('invoices.series.active', $series), [
            'is_active' => false,
        ]);

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHas('success', 'Seria numeracji została ukryta.');
        $this->assertFalse($series->refresh()->is_active);
    }

    public function test_inactive_custom_series_can_be_activated(): void
    {
        $series = $this->createCustomSeries('Seria nieaktywna');

        $response = $this->patch(route('invoices.series.active', $series), [
            'is_active' => true,
        ]);

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHas('success', 'Seria numeracji została aktywowana.');
        $this->assertTrue($series->refresh()->is_active);
    }

    public function test_active_state_endpoint_validates_expected_boolean_state(): void
    {
        $series = $this->createCustomSeries('Seria walidowana');

        $response = $this->patch(route('invoices.series.active', $series), [
            'is_active' => 'nieprawidłowy',
        ]);

        $response->assertSessionHasErrors([
            'is_active' => 'Oczekiwany stan serii numeracji jest nieprawidłowy.',
        ]);
        $this->assertFalse($series->refresh()->is_active);
    }

    public function test_system_series_cannot_be_deleted_by_direct_request(): void
    {
        $system = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        $response = $this->delete(route('invoices.series.destroy', $system));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors([
                'series' => 'Predefiniowanej serii systemowej nie można usunąć.',
            ]);
        $this->assertDatabaseHas('invoice_series', ['id' => $system->id]);
    }

    public function test_active_unreferenced_custom_series_can_be_deleted(): void
    {
        $series = $this->createCustomSeries('Aktywna do usunięcia', InvoiceDocumentType::Invoice, true);

        $this->get(route('invoices.series.index'))
            ->assertOk()
            ->assertSee('data-role="series-delete-form"', false)
            ->assertSee('data-series-id="'.$series->id.'"', false)
            ->assertDontSee(
                'data-role="series-delete-disabled" data-series-id="'.$series->id.'"',
                false,
            );

        $response = $this->delete(route('invoices.series.destroy', $series));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHas('success', 'Seria numeracji została usunięta.');
        $this->assertDatabaseMissing('invoice_series', ['id' => $series->id]);
    }

    public function test_referenced_correction_series_cannot_be_deleted(): void
    {
        $correction = $this->createCustomSeries(
            'Korekty powiązane',
            InvoiceDocumentType::Correction,
        );
        $this->createCustomSeries(
            'Faktury z korektami',
            InvoiceDocumentType::Invoice,
            false,
            $correction->id,
        );

        $response = $this->delete(route('invoices.series.destroy', $correction));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors([
                'series' => 'Nie można usunąć serii numeracji, ponieważ jest przypisana jako seria korekt.',
            ]);
        $this->assertDatabaseHas('invoice_series', ['id' => $correction->id]);
    }

    public function test_inactive_unreferenced_custom_series_can_be_deleted(): void
    {
        $series = $this->createCustomSeries('Seria do usunięcia');

        $response = $this->delete(route('invoices.series.destroy', $series));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHas('success', 'Seria numeracji została usunięta.');
        $this->assertDatabaseMissing('invoice_series', ['id' => $series->id]);
    }

    public function test_series_used_by_a_document_cannot_be_deleted_by_direct_request(): void
    {
        $series = $this->createCustomSeries('Seria użyta przez dokument');
        Invoice::query()->create([
            'invoice_series_id' => $series->id,
            'document_type' => InvoiceDocumentType::Invoice,
        ]);

        $response = $this->delete(route('invoices.series.destroy', $series));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors([
                'series' => 'Nie można usunąć serii numeracji, ponieważ została użyta w dokumentach. Serię można ukryć i później ponownie aktywować.',
            ]);
        $this->assertDatabaseHas('invoice_series', ['id' => $series->id]);
    }

    public function test_series_are_sorted_by_system_status_type_name_and_id(): void
    {
        $customInvoiceZ = $this->createCustomSeries('Zeta faktury');
        $customInvoiceA = $this->createCustomSeries('Alfa faktury');
        $customCorrection = $this->createCustomSeries('Alfa korekty', InvoiceDocumentType::Correction);
        $customProforma = $this->createCustomSeries('Alfa pro forma', InvoiceDocumentType::Proforma);

        $response = $this->get(route('invoices.series.index'));
        $displayedIds = $response->viewData('series')->getCollection()->pluck('id')->all();

        $this->assertSame([
            $this->systemSeries(InvoiceSeriesSystemKey::Invoice)->id,
            $this->systemSeries(InvoiceSeriesSystemKey::Correction)->id,
            $this->systemSeries(InvoiceSeriesSystemKey::Proforma)->id,
            $customInvoiceA->id,
            $customInvoiceZ->id,
            $customCorrection->id,
            $customProforma->id,
        ], $displayedIds);
    }

    public function test_series_list_paginates_ten_records_per_page(): void
    {
        foreach (range(1, 8) as $number) {
            $this->createCustomSeries(sprintf('Seria %02d', $number));
        }

        $firstPage = $this->get(route('invoices.series.index'));
        $secondPage = $this->get(route('invoices.series.index', ['page' => 2]));

        $this->assertSame(10, $firstPage->viewData('series')->count());
        $this->assertSame(11, $firstPage->viewData('series')->total());
        $this->assertSame(1, $secondPage->viewData('series')->count());
    }

    public function test_list_has_no_is_default_controls_and_has_active_create_and_edit_buttons(): void
    {
        $response = $this->get(route('invoices.series.index'));
        $content = $response->getContent();

        $response
            ->assertOk()
            ->assertDontSee('is_default')
            ->assertSee('data-role="new-series"', false)
            ->assertSee('data-role="series-edit"', false)
            ->assertDontSee('data-role="new-series-disabled"', false)
            ->assertDontSee('data-role="series-edit-disabled"', false);
        $this->assertDoesNotMatchRegularExpression('/data-role="new-series"[^>]*disabled/i', $content);
    }

    private function systemSeries(InvoiceSeriesSystemKey $key): InvoiceSeries
    {
        return InvoiceSeries::query()->where('system_key', $key->value)->firstOrFail();
    }

    private function createCustomSeries(
        string $name,
        InvoiceDocumentType $type = InvoiceDocumentType::Invoice,
        bool $active = false,
        ?int $defaultCorrectionSeriesId = null,
    ): InvoiceSeries {
        return InvoiceSeries::query()->create([
            'document_type' => $type,
            'name' => $name,
            'number_format' => strtoupper(substr(md5($name), 0, 6)).'/%N/%Y',
            'is_active' => $active,
            'default_correction_series_id' => $defaultCorrectionSeriesId,
        ])->refresh();
    }
}
