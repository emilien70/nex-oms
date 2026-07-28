<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\InvoiceSeries;
use Tests\TestCase;

class InvoiceSeriesFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_has_active_create_and_edit_controls_without_is_default_interface(): void
    {
        $response = $this->get(route('invoices.series.index'));

        $response
            ->assertOk()
            ->assertSee('data-role="new-series"', false)
            ->assertSee('data-role="series-edit"', false)
            ->assertSee('data-series-modal', false)
            ->assertDontSee('new-series-disabled')
            ->assertDontSee('series-edit-disabled')
            ->assertDontSee('is_default');
    }

    public function test_invoice_form_endpoint_returns_an_html_fragment_with_defaults(): void
    {
        $response = $this->get(route('invoices.series.form', ['document_type' => 'invoice']));

        $response
            ->assertOk()
            ->assertSee('data-series-form-fragment', false)
            ->assertSee('Faktura')
            ->assertSee('BL %N/%Y')
            ->assertDontSee('<html', false);
    }

    public function test_correction_form_endpoint_returns_expected_defaults(): void
    {
        $this->get(route('invoices.series.form', ['document_type' => 'correction']))
            ->assertOk()
            ->assertSee('Korekta')
            ->assertSee('BLK %N/%Y');
    }

    public function test_proforma_form_endpoint_returns_expected_defaults(): void
    {
        $this->get(route('invoices.series.form', ['document_type' => 'proforma']))
            ->assertOk()
            ->assertSee('Pro forma')
            ->assertSee('BLPF %N/%Y');
    }

    public function test_form_endpoint_rejects_invalid_document_type(): void
    {
        $this->getJson(route('invoices.series.form', ['document_type' => 'receipt']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_type');
    }

    public function test_edit_endpoint_returns_current_values_and_locks_system_fields(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertSee($series->name)
            ->assertSee($series->number_format)
            ->assertSee('data-role="system-document-type"', false)
            ->assertSee('Seria systemowa jest zawsze aktywna, a jej typu dokumentu nie można zmienić.');
    }

    public function test_each_supported_document_type_can_be_created_as_custom_series(): void
    {
        foreach ([
            InvoiceDocumentType::Invoice,
            InvoiceDocumentType::Correction,
            InvoiceDocumentType::Proforma,
        ] as $type) {
            $name = 'Własna '.$type->label();

            $this->post(route('invoices.series.store'), $this->validPayload([
                'document_type' => $type->value,
                'name' => $name,
                'number_format' => $type->defaultNumberFormat(),
            ]))
                ->assertRedirect(route('invoices.series.index'))
                ->assertSessionHas('success', 'Seria numeracji została utworzona.');

            $this->assertDatabaseHas('invoice_series', [
                'document_type' => $type->value,
                'name' => $name,
                'is_system' => false,
                'system_key' => null,
            ]);
        }
    }

    public function test_technical_fields_cannot_create_a_system_series(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Próba serii systemowej',
            'is_system' => true,
            'system_key' => InvoiceSeriesSystemKey::Invoice->value,
            'is_default' => true,
        ]))->assertRedirect(route('invoices.series.index'));

        $series = InvoiceSeries::query()->where('name', 'Próba serii systemowej')->firstOrFail();

        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);
        $this->assertArrayNotHasKey('is_default', $series->getAttributes());
    }

    public function test_store_trims_text_and_uppercases_currency(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => '  Seria przycięta  ',
            'number_format' => '  TEST %NN/%Y  ',
            'default_currency' => ' eur ',
        ]))->assertRedirect(route('invoices.series.index'));

        $this->assertDatabaseHas('invoice_series', [
            'name' => 'Seria przycięta',
            'number_format' => 'TEST %NN/%Y',
            'default_currency' => 'EUR',
        ]);
    }

    public function test_name_is_unique_within_document_type_but_allowed_for_another_type(): void
    {
        $this->post(route('invoices.series.store'), $this->validPayload([
            'name' => 'Wspólna nazwa',
        ]))->assertSessionDoesntHaveErrors();

        $this->from(route('invoices.series.index'))
            ->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Wspólna nazwa',
            ]))
            ->assertSessionHasErrors([
                'name' => 'Seria numeracji o tej nazwie już istnieje dla wybranego typu dokumentu.',
            ]);

        $this->post(route('invoices.series.store'), $this->validPayload([
            'document_type' => InvoiceDocumentType::Correction->value,
            'name' => 'Wspólna nazwa',
            'number_format' => 'KOR %N/%Y',
        ]))->assertSessionDoesntHaveErrors();

        $this->assertSame(2, InvoiceSeries::query()->where('name', 'Wspólna nazwa')->count());
    }

    public function test_number_format_requires_a_supported_sequence_token(): void
    {
        $this->from(route('invoices.series.index'))
            ->post(route('invoices.series.store'), $this->validPayload([
                'number_format' => 'BL /%Y',
            ]))
            ->assertSessionHasErrors([
                'number_format' => 'Format numeracji musi zawierać token %N.',
            ]);

        foreach (['%N/%Y', '%NN/%Y', '%NNN/%Y'] as $index => $format) {
            $this->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Format '.($index + 1),
                'number_format' => $format,
            ]))->assertSessionDoesntHaveErrors();
        }
    }

    public function test_fiscal_month_must_be_between_one_and_twelve(): void
    {
        foreach ([0, 13] as $month) {
            $this->from(route('invoices.series.index'))
                ->post(route('invoices.series.store'), $this->validPayload([
                    'name' => 'Miesiąc '.$month,
                    'fiscal_year_start_month' => $month,
                ]))
                ->assertSessionHasErrors('fiscal_year_start_month');
        }
    }

    public function test_store_and_update_requests_reject_unsafe_reset_period_formats(): void
    {
        $monthlyMessage = 'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.';

        $this->from(route('invoices.series.index'))
            ->post(route('invoices.series.store'), $this->validPayload([
                'name' => 'Miesięczna bez miesiąca',
                'number_format' => 'FA %N/%Y',
                'reset_period' => InvoiceSeriesResetPeriod::Monthly->value,
            ]))
            ->assertSessionHasErrors(['number_format' => $monthlyMessage]);

        $series = $this->createCustomSeries('Roczna do błędnej edycji');
        $yearlyFiscalMessage = 'Przy rocznym resetowaniu z początkiem roku innym niż styczeń format musi zawierać token miesiąca %M oraz token roku %Y lub %y.';

        $this->from(route('invoices.series.index'))
            ->patch(route('invoices.series.update', $series), $this->validPayload([
                'document_type' => $series->document_type->value,
                'name' => $series->name,
                'number_format' => 'FA %N/%Y',
                'reset_period' => InvoiceSeriesResetPeriod::Yearly->value,
                'fiscal_year_start_month' => 7,
                'form_mode' => 'edit',
                'editing_series_id' => $series->id,
            ]))
            ->assertSessionHasErrors(['number_format' => $yearlyFiscalMessage]);

        $this->assertSame(1, $series->refresh()->fiscal_year_start_month);
    }

    public function test_currency_must_contain_exactly_three_letters(): void
    {
        foreach (['PL', 'PLN1', '12A'] as $currency) {
            $this->from(route('invoices.series.index'))
                ->post(route('invoices.series.store'), $this->validPayload([
                    'name' => 'Waluta '.$currency,
                    'default_currency' => $currency,
                ]))
                ->assertSessionHasErrors([
                    'default_currency' => 'Waluta musi składać się z trzech liter.',
                ]);
        }
    }

    public function test_system_series_can_update_business_fields_but_not_protected_fields(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $originalType = $series->document_type;
        $originalKey = $series->system_key;

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => InvoiceDocumentType::Correction->value,
            'name' => 'Faktury główne',
            'number_format' => 'FV %NN/%M/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Monthly->value,
            'fiscal_year_start_month' => 4,
            'default_currency' => 'eur',
            'is_active' => false,
            'is_system' => false,
            'system_key' => InvoiceSeriesSystemKey::Correction->value,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHas('success', 'Seria numeracji została zaktualizowana.');

        $series->refresh();
        $this->assertSame('Faktury główne', $series->name);
        $this->assertSame('FV %NN/%M/%Y', $series->number_format);
        $this->assertSame(InvoiceSeriesResetPeriod::Monthly, $series->reset_period);
        $this->assertSame(4, $series->fiscal_year_start_month);
        $this->assertSame('EUR', $series->default_currency);
        $this->assertSame($originalType, $series->document_type);
        $this->assertSame($originalKey, $series->system_key);
        $this->assertTrue($series->is_system);
        $this->assertTrue($series->is_active);
    }

    public function test_custom_series_can_change_type_fields_and_activity_without_becoming_system(): void
    {
        $series = $this->createCustomSeries('Seria do edycji');

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => InvoiceDocumentType::Proforma->value,
            'name' => 'Pro forma własna',
            'number_format' => 'PF %NNN/%Y',
            'is_active' => false,
            'is_system' => true,
            'system_key' => InvoiceSeriesSystemKey::Proforma->value,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertRedirect(route('invoices.series.index'));

        $series->refresh();
        $this->assertSame(InvoiceDocumentType::Proforma, $series->document_type);
        $this->assertSame('Pro forma własna', $series->name);
        $this->assertSame('PF %NNN/%Y', $series->number_format);
        $this->assertFalse($series->is_active);
        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);

        $this->patch(route('invoices.series.update', $series), $this->validPayload([
            'document_type' => InvoiceDocumentType::Proforma->value,
            'name' => 'Pro forma własna',
            'number_format' => 'PF %NNN/%Y',
            'is_active' => true,
            'form_mode' => 'edit',
            'editing_series_id' => $series->id,
        ]))->assertSessionDoesntHaveErrors();

        $this->assertTrue($series->refresh()->is_active);
    }

    public function test_create_validation_error_preserves_modal_state_and_old_input(): void
    {
        $response = $this->from(route('invoices.series.index'))
            ->post(route('invoices.series.store'), $this->validPayload([
                'name' => '',
                'form_mode' => 'create',
            ]));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors('name')
            ->assertSessionHasInput('form_mode', 'create')
            ->assertSessionHasInput('document_type', InvoiceDocumentType::Invoice->value);

        $this->get(route('invoices.series.index'))
            ->assertOk()
            ->assertSee('data-reopen="1"', false)
            ->assertSee('Podaj nazwę serii.');
    }

    public function test_edit_validation_error_preserves_mode_and_series_id(): void
    {
        $series = $this->createCustomSeries('Edytowana seria');

        $response = $this->from(route('invoices.series.index'))
            ->patch(route('invoices.series.update', $series), $this->validPayload([
                'name' => '',
                'form_mode' => 'edit',
                'editing_series_id' => $series->id,
            ]));

        $response
            ->assertRedirect(route('invoices.series.index'))
            ->assertSessionHasErrors('name')
            ->assertSessionHasInput('form_mode', 'edit')
            ->assertSessionHasInput('editing_series_id', (string) $series->id);

        $this->get(route('invoices.series.index'))
            ->assertOk()
            ->assertSee('data-reopen="1"', false)
            ->assertSee('value="'.$series->id.'"', false)
            ->assertSee('Podaj nazwę serii.');
    }

    public function test_stage_one_b_activation_and_system_protection_still_work(): void
    {
        $custom = $this->createCustomSeries('Seria 1B');
        $system = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        $this->patch(route('invoices.series.active', $custom), ['is_active' => true])
            ->assertSessionHas('success', 'Seria numeracji została aktywowana.');
        $this->assertTrue($custom->refresh()->is_active);

        $this->patch(route('invoices.series.active', $system), ['is_active' => false])
            ->assertSessionHasErrors('series');
        $this->delete(route('invoices.series.destroy', $system))
            ->assertSessionHasErrors('series');
        $this->assertTrue($system->refresh()->is_active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'document_type' => InvoiceDocumentType::Invoice->value,
            'name' => 'Seria testowa '.uniqid(),
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly->value,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
            'form_mode' => 'create',
            'editing_series_id' => null,
        ], $overrides);
    }

    private function systemSeries(InvoiceSeriesSystemKey $key): InvoiceSeries
    {
        return InvoiceSeries::query()->where('system_key', $key->value)->firstOrFail();
    }

    private function createCustomSeries(string $name): InvoiceSeries
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
}
