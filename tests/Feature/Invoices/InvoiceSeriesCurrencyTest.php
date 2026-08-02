<?php

namespace Tests\Feature\Invoices;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceSeriesManagementService;
use Tests\TestCase;

class InvoiceSeriesCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_form_uses_local_currency_select(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        Http::preventStrayRequests();

        $this->get(route('invoices.series.form', ['document_type' => 'invoice']))
            ->assertOk()
            ->assertSee('value="PLN"', false)
            ->assertSee('value="EUR"', false)
            ->assertDontSee('euro')
            ->assertDontSee('—')
            ->assertDontSee('name="default_currency" type="text"', false);

        Http::assertNothingSent();
    }

    public function test_request_and_domain_service_reject_unknown_currency(): void
    {
        $payload = [
            'document_type' => InvoiceDocumentType::Invoice->value,
            'name' => 'Nieznana waluta',
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly->value,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'XXX',
            'is_active' => true,
        ];

        $this->post(route('invoices.series.store'), $payload)
            ->assertSessionHasErrors(['default_currency' => 'Wybierz prawidłową walutę.']);

        $this->expectException(\DomainException::class);
        app(InvoiceSeriesManagementService::class)->create($payload);
    }

    public function test_unchanged_historical_currency_is_preserved_but_new_unknown_value_is_blocked(): void
    {
        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Historyczna',
            'number_format' => 'H %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'XYZ',
            'is_active' => false,
        ]);

        app(InvoiceSeriesManagementService::class)->update($series, ['name' => 'Historyczna zmieniona']);
        $this->assertSame('XYZ', $series->refresh()->default_currency);

        $this->get(route('invoices.series.edit', $series))
            ->assertOk()
            ->assertSee('XYZ')
            ->assertDontSee('waluta historyczna')
            ->assertSee('value="XYZ" selected disabled', false);

        $this->expectException(\DomainException::class);
        app(InvoiceSeriesManagementService::class)->update($series, ['default_currency' => 'ABC']);
    }
}
