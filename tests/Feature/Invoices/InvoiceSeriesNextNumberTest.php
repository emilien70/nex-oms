<?php

namespace Tests\Feature\Invoices;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceNumberCounterAdjustment;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceNumberingService;
use Tests\TestCase;

class InvoiceSeriesNextNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_exposes_next_number_action_for_system_custom_and_hidden_series(): void
    {
        $custom = $this->createSeries('Wlasna aktywna');
        $hidden = $this->createSeries('Wlasna ukryta', ['is_active' => false]);

        $response = $this->get(route('invoices.series.index'))->assertOk();

        foreach (InvoiceSeries::query()->where('is_system', true)->get()->push($custom, $hidden) as $series) {
            $response->assertSee(route('invoices.series.next-number.form', $series), false);
        }
        $response->assertSee('Ustaw następny numer');
    }

    public function test_form_shows_series_period_counter_preview_reason_and_read_only_notice(): void
    {
        $series = $this->createSeries('Miesieczna', [
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'number_format' => 'TEST %N/%M/%Y',
            'is_active' => false,
        ]);

        $this->get(route('invoices.series.next-number.form', $series))
            ->assertOk()
            ->assertSee('Miesieczna')
            ->assertSee('Faktura')
            ->assertSee('Ukryta')
            ->assertSee('TEST %N/%M/%Y')
            ->assertSee('Miesięcznie')
            ->assertSee('Miesiąc okresu numeracji')
            ->assertSee('Aktualny ostatni numer kolejny')
            ->assertSee('Aktualny chroniony próg')
            ->assertSee('Aktualnie przewidywany następny numer')
            ->assertSee('Pełny podgląd numeru')
            ->assertSee('Powód zmiany')
            ->assertSee('Podgląd nie rezerwuje numeru');
    }

    public function test_backend_preview_uses_selected_period_and_candidate_without_writing(): void
    {
        $series = $this->createSeries('Podglad', [
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'number_format' => 'TEST %N/%M/%Y',
        ]);

        $this->getJson(route('invoices.series.next-number.preview', $series).'?period_month=2026-08&next_sequence_number=4251')
            ->assertOk()
            ->assertJsonPath('numbering_period_key', '2026-08')
            ->assertJsonPath('current_next_sequence_number', 1)
            ->assertJsonPath('preview_sequence_number', 4251)
            ->assertJsonPath('formatted_number', 'TEST 4251/08/2026');

        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_post_validates_period_number_reason_and_route_series_identity(): void
    {
        $series = $this->createSeries('Walidacja', [
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'number_format' => 'TEST %N/%M/%Y',
        ]);
        $other = $this->createSeries('Inna seria');

        $this->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $other->id,
            'period_month' => 'bledny',
            'next_sequence_number' => 0,
            'reason' => 'x',
        ])->assertSessionHasErrors([
            'next_number_series_id', 'period_month', 'next_sequence_number', 'reason',
        ]);

        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_preview_and_set_next_number_endpoints_reject_unsafe_series_configuration(): void
    {
        $series = $this->createSeries('Niebezpieczny endpoint', [
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
        ]);
        $message = 'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.';

        $this->get(route('invoices.series.next-number.form', $series))
            ->assertUnprocessable()
            ->assertSee($message);

        $this->getJson(route('invoices.series.next-number.preview', $series).'?period_month=2026-08&next_sequence_number=10')
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        $this->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $series->id,
            'period_month' => '2026-08',
            'next_sequence_number' => 10,
            'reason' => 'Test niebezpiecznej konfiguracji',
        ])
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors(['next_sequence_number' => $message]);

        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_valid_post_sets_next_number_creates_history_and_reports_polish_success(): void
    {
        $series = $this->createSeries('Seria POST');

        $this->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $series->id,
            'period_year' => 2026,
            'next_sequence_number' => 4251,
            'reason' => 'Kontynuacja numeracji z poprzedniego systemu',
        ])
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '4251'));

        $this->assertDatabaseHas('invoice_number_counters', [
            'invoice_series_id' => $series->id,
            'numbering_period_key' => '2026',
            'last_sequence_number' => 4250,
            'protected_floor_sequence_number' => 4250,
        ]);
        $this->assertDatabaseHas('invoice_number_counter_adjustments', [
            'numbering_period_key_snapshot' => '2026',
            'new_next_sequence_number' => 4251,
            'reason' => 'Kontynuacja numeracji z poprzedniego systemu',
        ]);
        $this->assertSame('application', InvoiceNumberCounterAdjustment::query()->firstOrFail()->actor_snapshot['type']);
    }

    public function test_client_supplied_period_key_and_counter_fields_are_ignored(): void
    {
        $series = $this->createSeries('Zaufanie backendowi');

        $this->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $series->id,
            'period_year' => 2026,
            'numbering_period_key' => 'attacker-period',
            'last_sequence_number' => 999999,
            'protected_floor_sequence_number' => 999999,
            'next_sequence_number' => 8,
            'reason' => 'Serwer wylicza stan samodzielnie',
        ])->assertRedirect(route('invoices.series.index'));

        $counter = InvoiceNumberCounter::query()->firstOrFail();
        $this->assertSame('2026', $counter->numbering_period_key);
        $this->assertSame(7, $counter->last_sequence_number);
        $this->assertSame(7, $counter->protected_floor_sequence_number);
    }

    public function test_validation_failure_reopens_the_same_next_number_modal(): void
    {
        $series = $this->createSeries('Ponowne otwarcie');

        $this->from(route('invoices.series.index'))->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $series->id,
            'period_year' => 2026,
            'next_sequence_number' => 10,
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->get(route('invoices.series.index'))
            ->assertOk()
            ->assertSee('data-reopen-series-id="'.$series->id.'"', false)
            ->assertSee('data-reopen-form-url="'.route('invoices.series.next-number.form', $series).'"', false)
            ->assertSee('data-reopen-store-url="'.route('invoices.series.next-number.store', $series).'"', false);
    }

    public function test_numbering_fields_are_locked_in_edit_form_after_manual_adjustment(): void
    {
        $series = $this->createSeries('Blokada formularza');
        $this->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $series->id,
            'period_year' => 2026,
            'next_sequence_number' => 10,
            'reason' => 'Rozpoczecie numeracji',
        ]);

        $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertSee('data-role="numbering-identity-locked"', false)
            ->assertSee('name="number_format" value="TEST %N/%Y"', false)
            ->assertSee('name="reset_period" value="yearly"', false)
            ->assertSee('name="fiscal_year_start_month" value="1"', false);
    }

    public function test_direct_patch_cannot_change_numbering_identity_after_numbering_started(): void
    {
        $series = $this->createSeries('Blokada PATCH');
        $this->post(route('invoices.series.next-number.store', $series), [
            'next_number_series_id' => $series->id,
            'period_year' => 2026,
            'next_sequence_number' => 10,
            'reason' => 'Rozpoczecie numeracji',
        ]);

        $this->patch(route('invoices.series.update', $series), $this->validUpdatePayload($series, [
            'number_format' => 'ZMIANA %N/%Y',
        ]))
            ->assertSessionHasErrors('numbering_identity');

        $this->assertSame('TEST %N/%Y', $series->refresh()->number_format);
    }

    public function test_started_system_series_can_edit_numbering_configuration_through_form_and_backend(): void
    {
        $series = InvoiceSeries::query()
            ->where('is_system', true)
            ->where('document_type', InvoiceDocumentType::Invoice->value)
            ->firstOrFail();
        $numbering = app(InvoiceNumberingService::class);
        $numbering->assignNextNumber(Invoice::query()->create([
            'invoice_series_id' => $series->id,
            'document_type' => $series->document_type,
            'status' => InvoiceDocumentStatus::Draft,
        ]), CarbonImmutable::parse('2026-07-15'));

        $content = $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertDontSee('data-role="numbering-identity-locked"', false)
            ->getContent();

        foreach (['number_format', 'reset_period', 'fiscal_year_start_month'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<(?:input|select)\b(?=[^>]*\bname="'.preg_quote($field, '/').'")(?![^>]*\bdisabled\b)[^>]*>/',
                $content,
            );
        }

        $this->patch(route('invoices.series.update', $series), $this->validUpdatePayload($series, [
            'number_format' => 'SYSTEM %N/%M/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Monthly->value,
            'fiscal_year_start_month' => 7,
        ]))
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionDoesntHaveErrors();

        $series->refresh();
        $this->assertSame('SYSTEM %N/%M/%Y', $series->number_format);
        $this->assertSame(InvoiceSeriesResetPeriod::Monthly, $series->reset_period);
        $this->assertSame(7, $series->fiscal_year_start_month);

        $this->patch(route('invoices.series.update', $series), $this->validUpdatePayload($series, [
            'number_format' => 'SYSTEM %N/%Y',
        ]))->assertSessionHasErrors('number_format');

        $this->assertSame('SYSTEM %N/%M/%Y', $series->refresh()->number_format);
    }

    private function createSeries(string $name, array $attributes = []): InvoiceSeries
    {
        return InvoiceSeries::query()->create(array_replace([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => $name,
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
        ], $attributes))->refresh();
    }

    private function validUpdatePayload(InvoiceSeries $series, array $overrides = []): array
    {
        return array_replace([
            'document_type' => $series->document_type->value,
            'name' => $series->name,
            'number_format' => $series->number_format,
            'reset_period' => $series->reset_period->value,
            'fiscal_year_start_month' => $series->fiscal_year_start_month,
            'default_currency' => $series->default_currency,
            'is_active' => $series->is_active,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ], $overrides);
    }
}
