<?php

namespace Tests\Feature\Invoices;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\Services\SalesRegisterHtmlPresenter;
use Modules\Invoices\Services\SalesRegisterValues;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesRegisterHttpTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        $this->travelTo(now()->setDate(2026, 9, 16));
    }

    public function test_form_defaults_history_choices_series_and_safe_return(): void
    {
        $hidden = InvoiceSeries::create(['document_type' => 'invoice', 'name' => 'Hidden with history', 'number_format' => 'H %N', 'is_active' => false]);
        InvoiceSeries::create(['document_type' => 'invoice', 'name' => 'Hidden without history', 'number_format' => 'X %N', 'is_active' => false]);
        $this->invoice(['invoice_series_id' => $hidden->id, 'currency' => 'DEM', 'issue_date' => '1999-01-01']);
        $response = $this->get(route('invoices.sales-register.create', ['return_to' => 'https://evil.invalid', 'return_query' => 'page=2&redirect=https://evil.invalid']));
        $response->assertOk()->assertSee('Rejestr sprzedaży — Faktury')->assertSee('Hidden with history')->assertDontSee('Hidden without history')->assertDontSee('https://evil.invalid');
        $dom = $this->dom($response->getContent());
        foreach (['month' => '9', 'year' => '2026', 'include_header' => '1', 'include_ksef' => '1', 'include_exchange_rates' => '0'] as $name => $value) {
            $this->assertSame($value, $dom->evaluate("string(//select[@name='$name']/option[@selected]/@value)"));
        }
        $this->assertSame('1999', $dom->evaluate('string(//select[@name="year"]/option[@value="1999"]/@value)'));
        $this->assertSame('DEM', $dom->evaluate('string(//select[@name="currency"]/option[@value="DEM"]/@value)'));
        $this->assertSame('DE', $dom->evaluate('string(//select[@name="country"]/option[@value="DE"]/@value)'));
        $this->assertSame(0, $dom->query('//input[@data-series-type="proforma"]')->length);
        $this->assertSame($dom->query('//input[@data-series-type]')->length, $dom->query('//input[@data-series-type and @checked]')->length);
        $this->assertSame(3, $dom->query('//button[@data-series-toggle and @type="button"]')->length);
        $this->assertSame('_blank', $dom->evaluate('string(//form[@id="salesRegisterForm"]/@target)'));
        $this->get(route('invoices.sales-register.create', ['return_to' => 'corrections', 'return_query' => 'page=3&sort=number']))
            ->assertSee(route('invoices.corrections.index', ['page' => 3, 'sort' => 'number']));
    }

    public function test_invoice_and_correction_menus_work_without_changing_other_bulk_actions(): void
    {
        foreach (['invoices.index', 'invoices.corrections.index'] as $route) {
            $response = $this->get(route($route));
            $response->assertOk()->assertSee('Według okresu i filtrów')->assertSee('Dla zaznaczonych dokumentów')->assertSee('DRUKUJ ZAZNACZONE')->assertSee('USUŃ ZAZNACZONE');
            $dom = $this->dom($response->getContent());
            $this->assertSame(1, $dom->query('//button[@data-register-selected and @disabled]')->length);
            $this->assertSame(1, $dom->query('//form[@id="salesRegisterSelectionForm"]//input[@name="_token"]')->length);
        }
        $proforma = $this->get(route('invoices.proformas.index'))->assertOk()->assertDontSee('REJESTR SPRZEDAŻY');
        $this->assertSame(0, $this->dom($proforma->getContent())->query('//form[@id="salesRegisterSelectionForm"]')->length);
    }

    public function test_module_navigation_opens_register_and_marks_its_form_as_active(): void
    {
        $url = route('invoices.sales-register.create');
        foreach (['invoices.index', 'invoices.proformas.index', 'invoices.corrections.index', 'invoices.sales-register.create'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $dom = $this->dom($response->getContent());
            $links = $dom->query('//nav[@class="invoice-module-tabs"]/a[@href="'.$url.'"]');
            $this->assertSame(1, $links->length);
            $link = $links->item(0);
            $this->assertSame('Rejestr sprzedaży', trim($link->textContent));
            $this->assertFalse($link->hasAttribute('aria-disabled'));
            $this->assertStringNotContainsString('disabled', $link->getAttribute('class'));
            $this->assertSame($route === 'invoices.sales-register.create', str_contains($link->getAttribute('class'), 'active'));
            $this->assertSame($route === 'invoices.sales-register.create' ? 'page' : '', $link->getAttribute('aria-current'));
        }
        $response = $this->export($this->period())->assertOk();
        $this->assertSame(0, $this->dom($response->getContent())->query('//nav')->length);
    }

    public function test_selected_form_and_export_use_only_ids_not_period_or_list_filters(): void
    {
        $a = $this->invoice(['issue_date' => '2020-01-01']);
        $b = $this->invoice();
        $payload = $this->selected([$a->id]) + ['month' => 'bad', 'year' => 'bad', 'series_ids' => [], 'currency' => 'EUR', 'country' => 'FR', 'tax_id_presence' => 'without', 'page' => 999];
        $form = $this->post(route('invoices.sales-register.selected'), $payload)->assertOk()->assertSee('Wybrane dokumenty:')->assertDontSee('name="series_ids[]"', false)->assertDontSee('name="month"', false);
        $this->assertSame('[1]', $form->viewData('values')['document_ids']);
        $response = $this->export($payload)->assertOk();
        $this->assertSame([$a->id], array_column($response->viewData('report')['records'], 'id'));
        $this->assertNotContains($b->id, array_column($response->viewData('report')['records'], 'id'));
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_form_input_is_controlled_and_retains_fields(array $changes, string $field): void
    {
        $this->invoice();
        $response = $this->export(array_replace($this->period(), $changes))->assertStatus(422);
        $this->assertTrue($response->viewData('errors')->has($field));
        $response->assertSee('Nie można wygenerować rejestru.')->assertSee('name="include_header"', false);
        if (isset($changes['document_ids'])) {
            $this->assertSame($changes['document_ids'], $response->viewData('values')['document_ids']);
        }
    }

    public static function invalidInputs(): array
    {
        return [
            'one issue date' => [['issue_from' => '2026-09-01'], 'issue_to'],
            'other issue date' => [['issue_to' => '2026-09-30'], 'issue_from'],
            'reverse issue' => [['issue_from' => '2026-09-30', 'issue_to' => '2026-09-01'], 'issue_to'],
            'invalid date' => [['issue_from' => '2026-02-30', 'issue_to' => '2026-03-01'], 'issue_from'],
            'reverse sale' => [['sale_from' => '2026-09-30', 'sale_to' => '2026-09-01'], 'sale_to'],
            'empty series' => [['series_ids' => []], 'series_ids'],
            'bad currency' => [['currency' => 'euro'], 'currency'],
            'unknown country filter' => [['country' => 'unknown'], 'country'],
            'nonboolean' => [['include_ksef' => 'false'], 'include_ksef'],
            'nonboolean header' => [['include_header' => 'yes'], 'include_header'],
            'nonboolean rates' => [['include_exchange_rates' => 'no'], 'include_exchange_rates'],
            'unsupported format' => [['format' => '../pdf'], 'format'],
            'unknown mode' => [['mode' => 'all'], 'mode'],
            'empty ids' => [['mode' => 'ids', 'document_ids' => '[]'], 'document_ids'],
            'broken ids' => [['mode' => 'ids', 'document_ids' => '[1,2'], 'document_ids'],
            'object ids' => [['mode' => 'ids', 'document_ids' => '{"id":1}'], 'document_ids'],
            'numeric object ids' => [['mode' => 'ids', 'document_ids' => '{"0":1}'], 'document_ids'],
            'string ids' => [['mode' => 'ids', 'document_ids' => '["1"]'], 'document_ids'],
            'negative ids' => [['mode' => 'ids', 'document_ids' => '[-1]'], 'document_ids'],
            'noninteger ids' => [['mode' => 'ids', 'document_ids' => '[1.5]'], 'document_ids'],
            'nested ids' => [['mode' => 'ids', 'document_ids' => '[[1]]'], 'document_ids'],
        ];
    }

    public function test_ineligible_documents_and_series_return_domain_errors_in_form(): void
    {
        foreach ([$this->invoice(['status' => 'draft'])->id, $this->invoice(['document_type' => 'proforma'])->id, 99999] as $id) {
            foreach (['invoices.sales-register.export', 'invoices.sales-register.selected'] as $route) {
                $response = $this->post(route($route), $this->selected([$id]))->assertStatus(422);
                $this->assertTrue($response->viewData('errors')->has('document_ids'));
                $this->assertSame('ids', $response->viewData('values')['mode']);
            }
        }
        foreach ([99999, $this->series('proforma')] as $series) {
            $response = $this->export(array_replace($this->period(), ['series_ids' => [$series]]))->assertStatus(422);
            $this->assertTrue($response->viewData('errors')->has('series_ids'));
        }
    }

    public function test_blank_dates_map_to_month_manual_dates_override_and_sale_bounds_are_independent(): void
    {
        $a = $this->invoice(['issue_date' => '2026-09-01', 'sale_date' => '2026-08-31']);
        $b = $this->invoice(['issue_date' => '2026-09-30', 'sale_date' => '2026-09-01']);
        $outside = $this->invoice(['issue_date' => '2026-08-01']);
        $response = $this->export($this->period())->assertOk()->assertSee('01.09.2026 do 30.09.2026');
        $this->assertSame([$a->id, $b->id], array_column($response->viewData('report')['records'], 'id'));
        $manual = $this->export(array_replace($this->period(), ['issue_from' => '2026-08-01', 'issue_to' => '2026-08-01']))->assertOk();
        $this->assertSame([$outside->id], array_column($manual->viewData('report')['records'], 'id'));
        foreach ([['sale_from' => '2026-09-01'], ['sale_to' => '2026-08-31']] as $index => $bound) {
            $filtered = $this->export(array_replace($this->period(), $bound))->assertOk();
            $this->assertSame([$index === 0 ? $b->id : $a->id], array_column($filtered->viewData('report')['records'], 'id'));
        }
    }

    public function test_html_columns_one_row_per_multivat_document_money_and_related_document(): void
    {
        $a = $this->invoice([], [$this->group(), $this->group('10.00', '0.80', '10.80', '8.00')]);
        $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $a->id], [$this->group('-10.00', '-2.30', '-12.30')]);
        foreach ([0, 1] as $include) {
            $response = $this->export(array_replace($this->period(), ['include_ksef' => $include]))->assertOk();
            $dom = $this->dom($response->getContent());
            $this->assertSame(10 + $include, $dom->query('//table[@class="register"]/thead/tr/th')->length);
            $this->assertSame(2, $dom->query('//tr[@data-document-id]')->length);
            $response->assertSee('23%, 8%')->assertSee("-12.30\u{00A0}PLN")->assertSee('Korekta FIX 2')->assertSee('Faktura FIX SOURCE');
            foreach ($dom->query('//table[@class="register"]//tr') as $row) {
                $width = 0;
                foreach ($row->childNodes as $cell) {
                    if ($cell instanceof \DOMElement) {
                        $width += (int) ($cell->getAttribute('colspan') ?: 1);
                    }
                }
                $this->assertSame(10 + $include, $width);
            }
        }
    }

    public function test_report_title_sits_above_columns_without_displacing_the_ksef_column(): void
    {
        $invoice = $this->invoice();
        foreach ([0, 1] as $includeKsef) {
            foreach ([0, 1] as $includeHeader) {
                $response = $this->export(array_replace($this->period(), ['include_ksef' => $includeKsef, 'include_header' => $includeHeader]))->assertOk();
                $dom = $this->dom($response->getContent());
                $this->assertSame($includeHeader, $dom->query('//thead/tr[@class="report-title"]')->length);
                $this->assertSame(10 + $includeKsef, $dom->query('//thead/tr[@class="column-headings"]/th')->length);
                $this->assertSame(2, $dom->query('//thead/tr[@class="column-headings"]/th[@class="date"]/br')->length);
                $this->assertSame($includeHeader * $includeKsef, $dom->query('//thead/tr/td[@class="title-gap"]')->length);
                $this->assertSame($includeHeader, $dom->query('//div[@class="report-metadata"]/p[@class="description"]')->length);
                $this->assertSame(1, $dom->query('//table[@class="register"]/following-sibling::div[@class="report-metadata"]/p[@class="count"]')->length);
                if ($includeHeader) {
                    $this->assertSame('10', $dom->evaluate('string(//thead/tr[@class="report-title"]/td[1]/@colspan)'));
                    $this->assertSame('Rejestr faktur sprzedaży za okres 01.09.2026 do 30.09.2026', $dom->evaluate('string(//h1)'));
                }
            }
        }
        $response = $this->export($this->selected([$invoice->id]))->assertOk();
        $this->assertSame('Rejestr faktur sprzedaży — wybrane dokumenty', $this->dom($response->getContent())->evaluate('string(//h1)'));
    }

    public function test_original_foreign_combined_country_shipping_and_rates_match_backend(): void
    {
        $this->invoice();
        $foreign = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion()]);
        $foreign->items()->first()->update(['line_type' => 'shipping']);
        $this->invoice(['currency' => 'EUR']);
        $response = $this->export(array_replace($this->period(), ['include_exchange_rates' => 1]))->assertOk();
        $response->assertSee('1 EUR = 4.00000000 PLN')->assertSee('FAKE/A/NBP/2026')->assertSee('PODSUMOWANIE WG KRAJU — Niemcy — EUR')->assertSee('Suma częściowa.');
        $response->assertSee('PODSUMOWANIE DOKUMENTÓW WYSTAWIONYCH W PLN')
            ->assertSee('PODSUMOWANIE DOKUMENTÓW WYSTAWIONYCH W EUR')
            ->assertSee('Podsumowanie stawek VAT')->assertSee('w tym koszty wysyłek');
        $report = $response->viewData('report');
        $backend = app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forPeriod(['month' => '2026-09', 'series_ids' => [$this->series('invoice'), $this->series('correction')], 'include_ksef' => false]));
        foreach (['foreign_in_pln', 'combined_pln', 'exchange_rates', 'completeness'] as $key) {
            $this->assertSame($backend['summaries'][$key], $report['summaries'][$key]);
        }
        $this->assertSame(2, $report['summaries']['foreign_in_pln']['document_count']);
        $this->assertSame(1, $report['summaries']['foreign_in_pln']['coverage']['totals']['included_count']);
        $this->assertSame('492.00', $report['summaries']['foreign_in_pln']['shipping']['totals']['gross']);
        $response->assertSee('Kwoty: uwzględniono 1 z 2; pominięto 1.')->assertSee('Kwoty: uwzględniono 2 z 3; pominięto 1.');
        $dom = $this->dom($response->getContent());
        $summaries = $dom->query('//tbody[@class="summary"]');
        $this->assertGreaterThan(0, $summaries->length);
        foreach ($summaries as $summary) {
            $this->assertSame(1, $dom->query('./tr[@class="coverage"]', $summary)->length);
            $this->assertSame(3, $dom->query('./tr[@class="coverage"]/td/span[@class="coverage-item"]', $summary)->length);
        }
        $without = $this->export($this->period())->assertOk()->assertDontSee('<h2>Tabela walut</h2>', false)->assertSee('ŁĄCZNE PODSUMOWANIE W PLN');
        $this->assertSame($report['summaries'], $without->viewData('report')['summaries']);
    }

    public function test_partial_unknown_and_selection_warnings_remain_visible_without_header(): void
    {
        $bad = $this->invoice(['currency' => '??', 'buyer_snapshot' => ['tax_id' => 'CONFLICT']]);
        DB::table('invoices')->where('id', $bad->id)->update(['total_net' => 'BAD', 'total_vat' => 'BAD', 'total_gross' => 'BAD']);
        $response = $this->export(array_replace($this->period(), ['include_header' => 0]))->assertOk()->assertDontSee('<header>', false)
            ->assertSee('Nieustalona waluta')->assertSee('Nieustalony kraj')->assertSee('Suma częściowa')->assertSee('Kompletność danych i ostrzeżenia')->assertDontSee('0.00')->assertDontSee('unknown');
        $this->assertNull($response->viewData('report')['summaries']['currencies']['unknown']['totals']);
        $selection = $this->export(array_replace($this->period(), ['tax_id_presence' => 'with', 'include_header' => 0]))->assertOk()
            ->assertSee('Brak dokumentów spełniających wybrane kryteria.')->assertSee('Dokument pominięty:')->assertSee('ID '.$bad->id);
        $this->assertSame(0, $selection->viewData('report')['selection']['record_count']);
    }

    public function test_pln_only_empty_report_and_empty_rate_table_do_not_show_duplicate_summaries(): void
    {
        $this->export($this->period())->assertOk()->assertSee('Liczba dokumentów: 0')->assertDontSee('PODSUMOWANIE');
        $this->invoice();
        $this->export(array_replace($this->period(), ['include_exchange_rates' => 1]))->assertOk()
            ->assertSee('Brak zapisanych kursów walut')->assertDontSee('ŁĄCZNE PODSUMOWANIE W PLN')->assertDontSee('WALUTOWYCH PRZELICZONYCH');
    }

    public function test_report_escapes_data_and_has_private_standalone_response_headers(): void
    {
        $xss = '<script>alert("fake")</script>';
        $invoice = $this->invoice(['number' => $xss, 'buyer_name_snapshot' => $xss, 'buyer_snapshot' => ['name' => $xss, 'tax_id' => 'DE-FAKE', 'street' => $xss, 'city' => $xss, 'country_code' => 'DE', 'country_name' => $xss]]);
        $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $invoice->id, 'number' => $xss.'COR']);
        $response = $this->export($this->period())->assertOk()->assertSee($xss)->assertDontSee($xss, false)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="rejestr-sprzedazy-2026-09-01_2026-09-30.html"');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $dom = $this->dom($response->getContent());
        $this->assertSame(0, $dom->query('//script|//link|//iframe|//form|//*[@src]|//*[@href]|//input[@name="_token"]')->length);
        $this->assertSame('pl', $dom->evaluate('string(/html/@lang)'));
        $response->assertDontSee('buyer_snapshot')->assertDontSee('api_token')->assertDontSee('sidebar');
    }

    public function test_real_csrf_middleware_rejects_missing_token_and_accepts_session_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        foreach (['invoices.sales-register.selected', 'invoices.sales-register.export'] as $route) {
            $this->post(route($route), $this->period())->assertStatus(419);
        }
        $this->withSession(['_token' => 'FAKE_CSRF_TOKEN'])->post(route('invoices.sales-register.export'), $this->period() + ['_token' => 'FAKE_CSRF_TOKEN'])->assertOk();
    }

    public function test_export_beyond_batch_and_pagination_is_read_only_with_no_ksef_queries(): void
    {
        $ids = [];
        for ($i = 0; $i < 205; $i++) {
            $ids[] = $this->invoice()->id;
        }
        $before = Invoice::all()->toArray();
        DB::enableQueryLog();
        $response = $this->export($this->selected($ids) + ['per_page' => 25, 'page' => 9])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(205, $response->viewData('report')['records']);
        $this->assertSame(205, $this->dom($response->getContent())->query('//tr[@data-document-id]')->length);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
            $this->assertStringNotContainsString('ksef_', $query['query']);
        }
        $this->assertSame($before, Invoice::all()->toArray());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_presenter_country_conflicts_money_and_unknown_warning_are_deterministic(): void
    {
        $a = $this->invoice();
        $this->invoice(['buyer_snapshot' => ['name' => 'Fixture buyer', 'tax_id' => 'DE-FAKE', 'country_code' => 'DE', 'country_name' => 'Germany']]);
        $this->invoice(['buyer_snapshot' => ['name' => 'Fixture buyer', 'tax_id' => 'DE-FAKE', 'country_code' => 'FR', 'country_name' => 'Francja']]);
        $response = $this->export($this->period())->assertOk()->assertSee('PODSUMOWANIE WG KRAJU — DE — PLN')->assertSee('PODSUMOWANIE WG KRAJU — Francja — PLN');
        $presenter = app(SalesRegisterHtmlPresenter::class);
        $this->assertSame('—', $presenter->money(null, 'PLN'));
        $this->assertSame("-0.01\u{00A0}EUR", $presenter->money('-0.01', 'EUR'));
        $this->assertSame('0.00', $presenter->money('0.00', null));
        $this->assertStringContainsString('unrecognized_code', $presenter->warning('unrecognized_code'));
        $this->assertSame($a->id, $response->viewData('report')['records'][0]['id']);
    }

    public function test_production_number_is_rendered_only_when_enabled_without_live_transport(): void
    {
        $invoice = $this->invoice();
        $this->acceptedFixture($invoice);
        $this->export(array_replace($this->selected([$invoice->id]), ['include_ksef' => 1]))->assertOk()
            ->assertSee('1234563218-20260901-000000000001-CA')->assertDontSee('FAKE_UNREADABLE_PAYLOAD');
        $this->export($this->selected([$invoice->id]))->assertOk()->assertDontSee('1234563218-20260901-000000000001-CA');
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_signed_corrections_do_not_include_source_outside_explicit_scope(): void
    {
        $source = $this->invoice();
        $positive = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id], [$this->group('20.00', '4.60', '24.60')]);
        $negative = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id], [$this->group('-10.00', '-2.30', '-12.30')]);
        $response = $this->export($this->selected([$positive->id, $negative->id]))->assertOk();
        $this->assertSame([$positive->id, $negative->id], array_column($response->viewData('report')['records'], 'id'));
        $this->assertSame('12.30', $response->viewData('report')['summaries']['currencies']['PLN']['totals']['gross']);
        $response->assertSee('Faktura FIX SOURCE')->assertSee("24.60\u{00A0}PLN")->assertSee("-12.30\u{00A0}PLN");
    }

    public function test_isolated_visual_fixtures_can_be_rendered_without_business_database(): void
    {
        $invoice = $this->invoice(['buyer_snapshot' => ['name' => 'Fixture buyer', 'tax_id' => 'DE-FAKE', 'street' => 'Ulica Testowa', 'building_number' => '10', 'postal_code' => '00-001', 'city' => 'Miasto testowe', 'country_code' => 'PL', 'country_name' => 'Polska']]);
        $this->acceptedFixture($invoice);
        $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion()]);
        $this->invoice(['document_type' => 'correction'], [$this->group('-10.00', '-2.30', '-12.30')]);
        $pages = [
            'form' => $this->get(route('invoices.sales-register.create'))->assertOk()->getContent(),
            'list' => $this->get(route('invoices.index'))->assertOk()->getContent(),
            'report' => $this->export(array_replace($this->period(), ['include_ksef' => 1, 'include_exchange_rates' => 1]))->assertOk()->getContent(),
            'no-ksef' => $this->export($this->period())->assertOk()->getContent(),
        ];
        $this->invoice(['currency' => 'EUR', 'tax_summary_snapshot' => null]);
        $pages['partial'] = $this->export(array_replace($this->period(), ['include_header' => 0]))->assertOk()->getContent();
        // Optional local visual artifacts contain only the isolated fixtures above, never user records.
        $directory = getenv('SALES_REGISTER_PREVIEW_DIR');
        if (is_string($directory) && is_dir($directory)) {
            foreach ($pages as $name => $html) {
                file_put_contents($directory.DIRECTORY_SEPARATOR.$name.'.html', $html);
            }
        }
        $this->assertCount(5, $pages);
    }

    private function export(array $payload): TestResponse
    {
        return $this->post(route('invoices.sales-register.export'), $payload);
    }

    private function period(): array
    {
        return ['mode' => 'period', 'month' => '9', 'year' => '2026', 'series_ids' => [$this->series('invoice'), $this->series('correction')],
            'issue_from' => '', 'issue_to' => '', 'sale_from' => '', 'sale_to' => '', 'currency' => '', 'country' => '', 'tax_id_presence' => 'all',
            'format' => 'html', 'include_header' => '1', 'include_exchange_rates' => '0', 'include_ksef' => '0'];
    }

    private function selected(array $ids): array
    {
        return ['mode' => 'ids', 'document_ids' => json_encode($ids), 'format' => 'html', 'include_header' => '1', 'include_exchange_rates' => '0', 'include_ksef' => '0'];
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($document);
    }

    private function series(string $type): int
    {
        return InvoiceSeries::where('system_key', $type)->value('id');
    }

    private function invoice(array $attributes = [], ?array $groups = null): Invoice
    {
        $type = $attributes['document_type'] ?? 'invoice';
        $groups ??= [$this->group()];
        $totals = app(SalesRegisterValues::class)->sum($groups);
        $invoice = Invoice::create(array_replace([
            'invoice_series_id' => $this->series($type), 'series_name_snapshot' => 'Seria testowa',
            'document_type' => $type, 'status' => 'issued', 'number' => 'FIX '.++$this->sequence,
            'issue_date' => '2026-09-01', 'sale_date' => '2026-09-01', 'currency' => 'PLN',
            'buyer_name_snapshot' => 'Fixture buyer', 'buyer_tax_id_snapshot' => 'DE-FAKE',
            'buyer_snapshot' => ['name' => 'Fixture buyer', 'tax_id' => 'DE-FAKE', 'street' => 'Ulica Testowa', 'building_number' => '10', 'postal_code' => '00-001', 'city' => 'Miasto testowe', 'country_code' => 'DE', 'country_name' => 'Niemcy'],
            'total_net' => $totals['net'], 'total_vat' => $totals['vat'], 'total_gross' => $totals['gross'], 'tax_summary_snapshot' => $groups,
            'tax_metadata_snapshot' => [], 'correction_totals_snapshot' => $type === 'correction' ? ['source_invoice' => ['number' => 'FIX SOURCE'], 'difference' => $totals + ['tax_summary_snapshot' => $groups]] : null,
        ], $attributes));
        $item = ['line_type' => 'product', 'position' => 1, 'name' => 'Fixture item', 'vat_rate' => '23.00', 'total_net' => '100.00', 'total_vat' => '23.00', 'total_gross' => '123.00'];
        $invoice->items()->create($item + ['correction_before_snapshot' => $item, 'correction_after_snapshot' => $item]);

        return $invoice;
    }

    private function group(string $net = '100.00', string $vat = '23.00', string $gross = '123.00', string $rate = '23.00'): array
    {
        return ['vat_rate' => $rate, 'vat_code' => null, 'net' => $net, 'vat' => $vat, 'gross' => $gross];
    }

    private function conversion(): array
    {
        return [
            'currency_conversion' => ['version' => 1, 'source' => 'NBP', 'source_currency' => 'EUR', 'target_currency' => 'PLN', 'table_type' => 'A', 'table_number' => 'FAKE/A/NBP/2026', 'effective_date' => '2026-08-31', 'reference_date' => '2026-09-01', 'rate' => '4.00000000', 'rate_rule' => 'vat_art_31a_standard_v1', 'rounding_mode' => 'half_up', 'result_scale' => 2],
            'converted_tax_summary' => ['currency' => 'PLN', 'groups' => [$this->group('400.00', '92.00', '492.00')], 'total_net' => '400.00', 'total_vat' => '92.00', 'total_gross' => '492.00'],
        ];
    }

    private function acceptedFixture(Invoice $invoice): void
    {
        $invoice->update(['seller_tax_id_snapshot' => '1234563218', 'seller_snapshot' => ['tax_id' => '1234563218']]);
        DB::table('ksef_invoice_submissions')->insert([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'attempt_number' => 1,
            'status' => 'accepted', 'schema_id' => 'FA(3)', 'generated_at' => '2026-09-01 12:00:00',
            'seller_nip' => '1234563218', 'context_nip' => '1234563218', 'payload_xml' => 'FAKE_UNREADABLE_PAYLOAD',
            'invoice_hash' => base64_encode(hash('sha256', 'FAKE_XML', true)), 'invoice_size' => 8,
            'ksef_number' => '1234563218-20260901-000000000001-CA', 'invoicing_mode' => 'Online',
        ]);
    }
}
