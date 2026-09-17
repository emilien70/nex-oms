<?php

namespace Tests\Feature\Invoices;

use App\Models\Order;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceTotalsCalculator;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\Services\SalesRegisterXmlExporter;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class SalesRegisterXmlTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private const FIELDS = ['no', 'invoice_number', 'ksef_id', 'date_invoice', 'date_sell', 'order_id', 'shop_order_id',
        'client_information', 'seller_information', 'items', 'order_items', 'payment', 'currency', 'currency_calc_rate',
        'receiver_country_code', 'total_price_netto', 'tax', 'total_tax', 'total_price_brutto'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
    }

    public function test_native_xml_structure_sources_and_private_download(): void
    {
        $invoice = $this->invoice([], [$this->state(), $this->state('1', '0', 'shipping')]);
        $response = $this->post(route('invoices.sales-register.export'), $this->selected([$invoice->id], true))->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('rejestr_sprzedazy_wybrane.xml');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $xml = $this->parse($this->bytes($response->baseResponse));
        $node = $xml->documentElement->getElementsByTagName('invoice')->item(0);
        $this->assertSame(self::FIELDS, $this->names($node));
        $this->assertSame(['invoice_fullname', 'invoice_address', 'invoice_postcode', 'invoice_city', 'invoice_state', 'invoice_country', 'invoice_nip'], $this->names($node->getElementsByTagName('client_information')->item(0)));
        $this->assertSame(['fv_seller'], $this->names($node->getElementsByTagName('seller_information')->item(0)));
        $this->assertSame(['item_product_id', 'item_name', 'item_sku', 'item_quantity', 'item_price_brutto', 'item_tax_rate', 'item_tax'], $this->names($xml->getElementsByTagName('item')->item(0)));
        foreach (['invoice_number' => 'Faktura XML 1', 'date_invoice' => '01.09.2026', 'date_sell' => '31.08.2026',
            'order_id' => '000501', 'shop_order_id' => '000909', 'invoice_nip' => '0012345678', 'invoice_address' => 'Testowa 2/0',
            'invoice_country' => 'Polska', 'invoice_state' => 'Testowe', 'receiver_country_code' => 'DE',
            'payment' => 'Gotówka historyczna', 'currency_calc_rate' => '0.000000', 'tax' => '23', 'total_price_brutto' => '123.00'] as $field => $value) {
            $this->assertSame($value, $xml->getElementsByTagName($field)->item(0)->textContent, $field);
        }
        $this->assertSame("Sprzedawca fikcyjny\nInna 3\n00-002 Miasto\nNIP: 1234563218\nBDO: 000001", $xml->getElementsByTagName('fv_seller')->item(0)->textContent);
        $this->assertSame('123.0000', $xml->getElementsByTagName('item_price_brutto')->item(0)->textContent);
        $this->assertSame('0.0000', $xml->getElementsByTagName('item_price_brutto')->item(1)->textContent);
        $this->assertSame('Przesyłka: 23% VAT: Taka sama nazwa', $xml->getElementsByTagName('item_name')->item(1)->textContent);
        $this->assertSame('', $xml->getElementsByTagName('order_items')->item(0)->textContent);
        $this->assertStringContainsString('xml_order_items_unavailable', $xml->saveXML());
        $this->assertStringNotContainsString('SECRET_BANK', $xml->saveXML());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    #[DataProvider('correctionCases')]
    public function test_correction_preserves_paired_before_after_and_signed_difference(string $beforeQty, string $beforePrice, string $afterQty, string $afterPrice, string $gross): void
    {
        $before = $this->state($beforeQty, $beforePrice);
        $after = $this->state($afterQty, $afterPrice);
        $correction = $this->correction([$before], [$after]);
        $xml = $this->export([$correction->id]);
        $xpath = new DOMXPath($xml);
        $this->assertSame($afterQty, $xpath->evaluate('string(//items/item/item_quantity)'));
        $this->assertSame($beforeQty, $xpath->evaluate('string(//original_items/item/item_quantity)'));
        $this->assertSame(number_format((float) $afterPrice, 4, '.', ''), $xpath->evaluate('string(//items/item/item_price_brutto)'));
        $this->assertSame($gross, $xpath->evaluate('string(//total_price_brutto)'));
        $this->assertSame('23.00', $xpath->evaluate('string(//tax)'));
        $this->assertSame('SOURCE FAKE', $xpath->evaluate('string(//correction_to_invoice_number)'));
        $this->assertSame(['item_name', 'item_quantity', 'item_price_brutto', 'item_tax_rate'], $this->names($xml->getElementsByTagName('original_items')->item(0)->firstChild));
        $this->assertStringContainsString('Sprzedawca fikcyjny', $xpath->evaluate('string(//fv_seller)'));
        $names = self::FIELDS;
        array_splice($names, 2, 1, ['correction_to_invoice_number']);
        array_splice($names, array_search('order_items', $names), 0, ['original_items']);
        $this->assertSame($names, $this->names($xml->getElementsByTagName('invoice')->item(0)));
    }

    public static function correctionCases(): array
    {
        return [
            'partial return' => ['2', '100', '1', '100', '-100.00'],
            'full return' => ['2', '100', '0', '100', '-200.00'],
            'price' => ['2', '100', '2', '90', '-20.00'],
            'added' => ['0', '100', '1', '100', '100.00'],
            'data only' => ['1', '100', '1', '100', '0.00'],
            'fractional' => ['1.25', '12.3456', '0.5', '12.3456', '-9.26'],
        ];
    }

    public function test_next_correction_and_duplicate_names_do_not_reconstruct_source_or_reorder_pairs(): void
    {
        $first = $this->correction([$this->state('3')], [$this->state('2')]);
        $next = $this->correction([$this->state('2', '123', 'shipping'), $this->state('0', '10')],
            [$this->state('1', '123', 'product'), $this->state('1', '10', 'shipping')], ['previous_correction_id' => $first->id]);
        $xpath = new DOMXPath($this->export([$next->id]));
        $this->assertSame('2', $xpath->evaluate('string(//original_items/item[1]/item_quantity)'));
        $this->assertSame('0', $xpath->evaluate('string(//original_items/item[2]/item_quantity)'));
        $this->assertSame('Taka sama nazwa', $xpath->evaluate('string(//items/item[1]/item_name)'));
        $this->assertStringStartsWith('Przesyłka:', $xpath->evaluate('string(//original_items/item[1]/item_name)'));
        $this->assertStringStartsWith('Przesyłka:', $xpath->evaluate('string(//items/item[2]/item_name)'));
        $this->assertSame('Taka sama nazwa', $xpath->evaluate('string(//original_items/item[2]/item_name)'));
        $this->assertSame('-113.00', $xpath->evaluate('string(//total_price_brutto)'));
    }

    public function test_history_survives_changed_deleted_order_and_never_fakes_order_items(): void
    {
        $order = Order::create(['source' => 'allegro', 'external_id' => 'FAKE-UUID', 'status' => 'new', 'currency' => 'PLN']);
        $invoice = $this->invoice(['order_id' => $order->id, 'order_snapshot' => ['id' => '000501', 'source' => 'allegro', 'external_id' => 'FAKE-UUID']]);
        $invoice->items()->first()->update(['product_id' => 777, 'product_snapshot' => ['order_item_id' => 999, 'name' => 'NOT A COLLECTION', 'sku' => '000SKU']]);
        $before = $this->export([$invoice->id])->saveXML();
        $order->update(['external_id' => 'CHANGED', 'billing_name' => 'CHANGED', 'payment_method' => 'CHANGED']);
        $this->assertSame($before, $this->export([$invoice->id])->saveXML());
        $order->delete();
        $this->assertSame($before, $this->export([$invoice->id])->saveXML());
        $xpath = new DOMXPath($this->parse($before));
        $this->assertSame('777', $xpath->evaluate('string(//item_product_id)'));
        $this->assertSame('000SKU', $xpath->evaluate('string(//item_sku)'));
        $this->assertSame('', $xpath->evaluate('string(//shop_order_id)'));
        $this->assertSame(0, $xpath->query('//order_item')->length);
        $invoice->items()->first()->update(['name' => 'EDITED DOCUMENT']);
        $xml = $this->export([$invoice->id]);
        $this->assertSame('EDITED DOCUMENT', $xml->getElementsByTagName('item_name')->item(0)->textContent);
        $this->assertSame(0, $xml->getElementsByTagName('order_item')->length);
    }

    public function test_optional_fields_are_empty_without_false_country_or_identifier_fallbacks(): void
    {
        $invoice = $this->invoice(['order_reference_snapshot' => null, 'order_snapshot' => [], 'recipient_snapshot' => [],
            'payment_snapshot' => ['effective_payment_method' => null, 'order_payment_method' => 'NOT USED']]);
        $xml = $this->export([$invoice->id]);
        foreach (['order_id', 'shop_order_id', 'item_product_id', 'item_sku', 'receiver_country_code', 'payment'] as $field) {
            $this->assertSame('', $xml->getElementsByTagName($field)->item(0)->textContent);
        }
        $this->assertSame(1, $xml->getElementsByTagName('item')->length);
        $this->assertSame(0, $xml->getElementsByTagName('correction_to_invoice_number')->length);
        $this->assertSame(0, $xml->getElementsByTagName('original_items')->length);
    }

    public function test_foreign_rate_keeps_own_precision_and_missing_rate_stays_empty(): void
    {
        $invoice = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => [
            'currency_conversion' => ['version' => 1, 'source' => 'NBP', 'source_currency' => 'EUR', 'target_currency' => 'PLN',
                'table_type' => 'A', 'table_number' => 'FAKE/A/2026', 'effective_date' => '2026-08-31', 'reference_date' => '2026-09-01',
                'rate' => '4.00000000001234567890', 'rate_rule' => 'vat_art_31a_standard_v1', 'rounding_mode' => 'half_up', 'result_scale' => 2],
            'converted_tax_summary' => ['currency' => 'PLN', 'groups' => [['vat_rate' => '23.00', 'vat_code' => null, 'net' => '400.00', 'vat' => '92.00', 'gross' => '492.00']],
                'total_net' => '400.00', 'total_vat' => '92.00', 'total_gross' => '492.00'],
        ]]);
        $this->assertSame('4.00000000001234567890', $this->export([$invoice->id])->getElementsByTagName('currency_calc_rate')->item(0)->textContent);
        $invoice->update(['tax_metadata_snapshot' => []]);
        $xml = $this->export([$invoice->id]);
        $this->assertSame('', $xml->getElementsByTagName('currency_calc_rate')->item(0)->textContent);
        $this->assertStringContainsString('exchange_rate_unavailable', $xml->saveXML());
    }

    #[DataProvider('badItems')]
    public function test_invalid_item_values_fail_before_download(string $field, mixed $value, string $expected): void
    {
        if ($value === null && $field === 'quantity' || is_bool($value) || is_array($value)) {
            $invoice = $this->correction([$this->state('2')], [$this->state('1')]);
            $item = $invoice->items()->first();
            $item->update(['correction_after_snapshot' => array_replace($item->correction_after_snapshot, [$field => $value])]);
        } else {
            $invoice = $this->invoice();
            DB::table('invoice_items')->where('invoice_id', $invoice->id)->update([$field => $value]);
        }
        $this->invalidExport($invoice, $expected);
    }

    public static function badItems(): array
    {
        return [
            'quantity malformed' => ['quantity', 'BAD', 'quantity'], 'quantity null' => ['quantity', null, 'quantity'],
            'quantity boolean' => ['quantity', false, 'quantity'], 'price array' => ['unit_price_gross', [], 'unit_price_gross'],
            'quantity precision' => ['quantity', '1.23456', 'precyzję'], 'negative quantity' => ['quantity', '-1', 'quantity'],
            'price malformed' => ['unit_price_gross', 'BAD', 'unit_price_gross'], 'price precision' => ['unit_price_gross', '1.23456', 'precyzję'],
            'tax code' => ['vat_code', 'ZW', 'kodów VAT'], 'missing rate' => ['vat_rate', null, '.vat'],
            'invalid XML' => ['name', "FAKE\x01NAME", 'XML 1.0'], 'invalid UTF8' => ['name', "FAKE\xC3\x28", 'UTF-8'],
        ];
    }

    #[DataProvider('badCorrections')]
    public function test_missing_or_malformed_correction_states_are_not_reconstructed(string $field, mixed $value): void
    {
        $invoice = $this->correction([$this->state('2')], [$this->state('1')]);
        $item = $invoice->items()->first();
        $item->update([$field => $value]);
        $this->invalidExport($invoice, 'Dokument ID '.$invoice->id);
    }

    public static function badCorrections(): array
    {
        return [['correction_before_snapshot', null], ['correction_after_snapshot', null], ['correction_before_snapshot', []], ['correction_after_snapshot', ['quantity' => '0']]];
    }

    public function test_ambiguous_vat_and_missing_document_financial_values_do_not_export_partial_file(): void
    {
        $good = $this->invoice();
        $bad = $this->invoice([], [$this->state(), $this->state('1', '108', 'product', '8.00')]);
        $this->post(route('invoices.sales-register.export'), $this->selected([$good->id, $bad->id]))->assertStatus(422)->assertSee('wieloma stawkami');
        $bad->update(['number' => null]);
        $this->invalidExport($bad, 'number');
        $bad->update(['number' => 'FIX', 'sale_date' => null]);
        $this->invalidExport($bad, 'sale_date');
        $bad->update(['sale_date' => '2026-09-01', 'tax_summary_snapshot' => null]);
        $this->invalidExport($bad, 'Dokument ID');
        $correction = $this->correction([$this->state('2')], [$this->state('1')]);
        $correction->update(['correction_totals_snapshot' => null]);
        $this->invalidExport($correction, 'correction');
    }

    public function test_xml_text_round_trip_injections_comments_and_no_external_resolution(): void
    {
        $text = "Żółć & <invoice>fake</invoice> \" ' ]]> <!DOCTYPE a [<!ENTITY x SYSTEM 'file:///DO-NOT-READ'>]> -- -";
        $invoice = $this->invoice(['payment_snapshot' => ['effective_payment_method' => $text]]);
        $invoice->items()->first()->update(['name' => $text]);
        $xml = $this->export([$invoice->id]);
        $this->assertSame($text, $xml->getElementsByTagName('payment')->item(0)->textContent);
        $this->assertSame($text, $xml->getElementsByTagName('item_name')->item(0)->textContent);
        $this->assertSame(1, $xml->getElementsByTagName('invoice')->length);
        $report = $this->report([$invoice->id]);
        $report['warnings'][] = ['code' => 'sales_register_FAKE---', 'document_id' => $invoice->id, 'section' => 'document'];
        $xml = $this->parse($this->bytes(app(SalesRegisterXmlExporter::class)->download($report, SalesRegisterFilters::forDocuments([$invoice->id], false))));
        foreach ((new DOMXPath($xml))->query('//comment()') as $comment) {
            $this->assertStringNotContainsString('--', $comment->data);
            $this->assertFalse(str_ends_with($comment->data, '-'));
        }
    }

    public function test_only_own_production_ksef_number_is_used_and_no_authorization_date_is_added(): void
    {
        $source = $this->invoice();
        $correction = $this->correction([$this->state('2')], [$this->state('1')]);
        $this->accepted($source, 'production');
        $this->accepted($correction, 'test');
        $this->accepted($correction, 'demo');
        $xml = $this->export([$source->id, $correction->id], true);
        $xpath = new DOMXPath($xml);
        $this->assertSame('1234563218-20260901-000000000001-CA', $xpath->evaluate('string(//invoice[1]/ksef_id)'));
        $this->assertSame('', $xpath->evaluate('string(//invoice[2]/ksef_id)'));
        $this->assertSame(0, $xpath->query('//*[contains(name(), "authorization") or contains(name(), "acquisition")]')->length);
        $this->accepted($correction, 'production');
        $this->assertSame('1234563218-20260901-000000000001-CA', $this->export([$correction->id], true)->getElementsByTagName('ksef_id')->item(0)->textContent);
        $correction->update(['seller_tax_id_snapshot' => '9876543210']);
        $this->assertSame('', $this->export([$correction->id], true)->getElementsByTagName('ksef_id')->item(0)->textContent);
    }

    public function test_period_and_ids_over_batch_limit_match_html_xlsx_without_domain_writes_or_n_plus_one(): void
    {
        $ids = [];
        for ($i = 0; $i < 205; $i++) {
            $ids[] = $this->invoice()->id;
        }
        $this->invoice(['issue_date' => '2026-08-01']);
        $before = DB::table('invoices')->get()->toJson().DB::table('invoice_items')->get()->toJson();
        foreach ([$this->period(), $this->selected($ids) + ['page' => 99, 'per_page' => 10]] as $payload) {
            $html = $this->post(route('invoices.sales-register.export'), array_replace($payload, ['format' => 'html', 'include_header' => 1, 'include_exchange_rates' => 0]))->assertOk();
            $records = $html->viewData('report')['records'];
            $xlsxResponse = $this->post(route('invoices.sales-register.export'), array_replace($payload, ['format' => 'xlsx']))->assertOk();
            $path = tempnam(sys_get_temp_dir(), 'rs-xml-xlsx-');
            try {
                file_put_contents($path, $this->bytes($xlsxResponse->baseResponse));
                $book = (new Xlsx)->load($path);
            } finally {
                unlink($path);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $xml = $this->parse($this->bytes($this->post(route('invoices.sales-register.export'), $payload)->assertOk()->baseResponse));
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThan(22, count($queries));
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
                $this->assertDoesNotMatchRegularExpression('/ksef_|from "orders"|from "order_items"|from "products"/', $query['query']);
            }
            $this->assertSame(205, $xml->getElementsByTagName('invoice')->length);
            $this->assertSame(0, $xml->getElementsByTagName('ksef_id')->length);
            $this->assertEqualsCanonicalizing($ids, array_column($records, 'id'));
            foreach ($records as $index => $record) {
                $node = $xml->getElementsByTagName('invoice')->item($index);
                $this->assertSame('Faktura '.$record['number'], $node->getElementsByTagName('invoice_number')->item(0)->textContent);
                $this->assertSame((string) $record['ordinal'], $node->getElementsByTagName('no')->item(0)->textContent);
                $this->assertSame($record['number'], $book->getActiveSheet()->getCell('C'.($index + 2))->getValue());
                foreach (['total_price_netto' => ['net', 'K'], 'total_tax' => ['vat', 'M'], 'total_price_brutto' => ['gross', 'N']] as $field => [$amount, $column]) {
                    $this->assertSame($record['totals'][$amount], $node->getElementsByTagName($field)->item(0)->textContent);
                    $this->assertSame($record['totals'][$amount], sprintf('%.2F', $book->getActiveSheet()->getCell($column.($index + 2))->getValue()));
                }
            }
            $book->disconnectWorksheets();
        }
        $this->assertSame($before, DB::table('invoices')->get()->toJson().DB::table('invoice_items')->get()->toJson());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_empty_selection_warnings_validation_and_html_options(): void
    {
        $xml = $this->parse($this->bytes($this->post(route('invoices.sales-register.export'), $this->period())->assertOk()->assertDownload('rejestr_sprzedazy_20260901_20260930.xml')->baseResponse));
        $this->assertSame(0, $xml->getElementsByTagName('invoice')->length);
        $invoice = $this->invoice(['buyer_tax_id_snapshot' => 'CONFLICT']);
        $xml = $this->parse($this->bytes($this->post(route('invoices.sales-register.export'), array_replace($this->period(), ['tax_id_presence' => 'with']))->assertOk()->baseResponse));
        $this->assertSame(0, $xml->getElementsByTagName('invoice')->length);
        $this->assertStringContainsString('tax_id_filter_unresolved', $xml->saveXML());
        $this->assertStringContainsString('ID '.$invoice->id, $xml->saveXML());
        $response = $this->post(route('invoices.sales-register.export'), array_replace($this->period(), ['series_ids' => []]))->assertStatus(422);
        $this->assertSame('xml', $response->viewData('values')['format']);
        $this->assertFalse($response->headers->has('Content-Disposition'));
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent(), LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $this->assertSame('xml', $xpath->evaluate('string(//input[@name="format" and @checked]/@value)'));
        $this->assertSame('_self', $xpath->evaluate('string(//form[@id="salesRegisterForm"]/@target)'));
        $this->assertSame(2, $xpath->query('//*[@data-html-option and @hidden]//select[@disabled]')->length);
        $good = $this->invoice();
        $plain = $this->export([$good->id])->saveXML();
        $options = $this->selected([$good->id]) + ['include_header' => ['bad'], 'include_exchange_rates' => 'bad'];
        $this->assertSame($plain, $this->parse($this->bytes($this->post(route('invoices.sales-register.export'), $options)->assertOk()->baseResponse))->saveXML());
    }

    public function test_safe_failure_cleans_only_its_own_temp_file(): void
    {
        $invoice = $this->invoice();
        $before = glob(storage_path('app/private/sales-register-exports/register-*.xml'));
        $this->mock(ResponseFactory::class, fn ($mock) => $mock->shouldReceive('download')->once()->andThrow(new \RuntimeException('FAKE_PRIVATE_DETAIL')));
        try {
            app(SalesRegisterXmlExporter::class)->download($this->report([$invoice->id]), SalesRegisterFilters::forDocuments([$invoice->id], false));
            $this->fail('Expected controlled failure.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('sales_register_xml_failed', $exception->errorCode());
            $this->assertStringNotContainsString('FAKE_PRIVATE_DETAIL', $exception->getMessage());
        }
        $this->assertSame($before, glob(storage_path('app/private/sales-register-exports/register-*.xml')));
    }

    public function test_single_percentage_rates_and_fractional_unit_precision_are_preserved(): void
    {
        foreach (['0.00', '8.00', '23.00'] as $rate) {
            $invoice = $this->invoice([], [$this->state('1.25', '12.3456', 'product', $rate)]);
            $xml = $this->export([$invoice->id]);
            $this->assertSame('1.25', $xml->getElementsByTagName('item_quantity')->item(0)->textContent);
            $this->assertSame('12.3456', $xml->getElementsByTagName('item_price_brutto')->item(0)->textContent);
            $this->assertSame($rate, $xml->getElementsByTagName('item_tax_rate')->item(0)->textContent);
            $this->assertSame(rtrim(rtrim($rate, '0'), '.'), $xml->getElementsByTagName('tax')->item(0)->textContent);
            $this->assertSame('15.43', $xml->getElementsByTagName('total_price_brutto')->item(0)->textContent);
        }
    }

    public function test_original_vat_code_and_conflicting_source_number_are_controlled_errors(): void
    {
        $invoice = $this->correction([$this->state('2')], [$this->state('1')]);
        $item = $invoice->items()->first();
        $before = $item->correction_before_snapshot;
        $item->update(['correction_before_snapshot' => array_replace($before, ['vat_code' => 'ZW', 'vat_rate' => null])]);
        $this->invalidExport($invoice, 'kodów VAT');
        $item->update(['correction_before_snapshot' => $before]);
        $invoice->update(['order_snapshot' => ['corrected_invoice' => ['number' => 'DIFFERENT SOURCE']]]);
        $this->invalidExport($invoice, 'jednoznacznego');
    }

    public function test_invalid_text_is_not_hidden_by_shared_whitespace_normalization(): void
    {
        $invoice = $this->invoice(['payment_snapshot' => ['effective_payment_method' => "\x00"]]);
        $this->invalidExport($invoice, 'payment');
        $invoice->update(['payment_snapshot' => ['effective_payment_method' => null], 'buyer_name_snapshot' => "Nabywca fikcyjny\x00"]);
        $this->invalidExport($invoice, 'buyer_name_snapshot');
    }

    public function test_fake_example_can_be_written_outside_the_repository_for_manual_review(): void
    {
        $invoice = $this->invoice([], [$this->state(), $this->state('1', '0', 'shipping')]);
        $correction = $this->correction([$this->state('2', '100')], [$this->state('1', '100')]);
        $response = $this->post(route('invoices.sales-register.export'), $this->selected([$invoice->id, $correction->id], true))->assertOk();
        $bytes = $this->bytes($response->baseResponse);
        $this->assertSame(2, $this->parse($bytes)->getElementsByTagName('invoice')->length);
        $directory = getenv('SALES_REGISTER_PREVIEW_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'rejestr-sprzedazy-przyklad.xml', $bytes);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'form-xml.html', $this->get(route('invoices.sales-register.create'))->assertOk()->getContent());
        }
    }

    private function invalidExport(Invoice $invoice, string $message): void
    {
        $before = glob(storage_path('app/private/sales-register-exports/register-*.xml'));
        $response = $this->post(route('invoices.sales-register.export'), $this->selected([$invoice->id]))->assertStatus(422)->assertSee($message);
        $this->assertFalse($response->headers->has('Content-Disposition'));
        $this->assertSame($before, glob(storage_path('app/private/sales-register-exports/register-*.xml')));
    }

    private function export(array $ids, bool $ksef = false): DOMDocument
    {
        return $this->parse($this->bytes($this->post(route('invoices.sales-register.export'), $this->selected($ids, $ksef))->assertOk()->baseResponse));
    }

    private function bytes(BinaryFileResponse $response): string
    {
        $path = $response->getFile()->getPathname();
        $this->assertSame(realpath(storage_path('app/private/sales-register-exports')), realpath(dirname($path)));
        ob_start();
        try {
            $response->sendContent();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertFileDoesNotExist($path);

        return $bytes;
    }

    private function parse(string $bytes): DOMDocument
    {
        $this->assertMatchesRegularExpression('/\A<\?xml version="1\.0" encoding="utf-8"\?>/i', $bytes);
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $this->assertTrue($document->loadXML($bytes, LIBXML_NONET));
        $this->assertNull($document->doctype);
        $this->assertSame('invoices', $document->documentElement->nodeName);
        $this->assertSame('', $document->documentElement->namespaceURI ?? '');
        $this->assertSame(0, (new DOMXPath($document))->query('//@* | //processing-instruction()')->length);

        return $document;
    }

    private function names(DOMElement $node): array
    {
        return array_map(static fn ($child) => $child->nodeName, array_values(array_filter(iterator_to_array($node->childNodes), static fn ($child) => $child instanceof DOMElement)));
    }

    private function selected(array $ids, bool $ksef = false): array
    {
        return ['mode' => 'ids', 'document_ids' => json_encode($ids), 'format' => 'xml', 'include_ksef' => (int) $ksef];
    }

    private function period(): array
    {
        return ['mode' => 'period', 'month' => 9, 'year' => 2026, 'series_ids' => InvoiceSeries::whereIn('system_key', ['invoice', 'correction'])->pluck('id')->all(),
            'tax_id_presence' => 'all', 'format' => 'xml', 'include_ksef' => 0];
    }

    private function report(array $ids): array
    {
        return app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forDocuments($ids, false));
    }

    private function state(string $quantity = '1', string $price = '123', string $type = 'product', string $rate = '23.00'): array
    {
        $gross = app(InvoiceDecimalCalculator::class)->multiplyAndRound($quantity, $price, 2);

        return ['name' => 'Taka sama nazwa', 'position' => 1, 'line_type' => $type, 'quantity' => $quantity, 'vat_rate' => $rate, 'vat_code' => null]
            + app(InvoiceTotalsCalculator::class)->calculateLine($price, $gross, $rate);
    }

    private function invoice(array $attributes = [], ?array $states = null): Invoice
    {
        $states ??= [$this->state()];
        $type = $attributes['document_type'] ?? 'invoice';
        $invoice = Invoice::create(array_replace([
            'invoice_series_id' => InvoiceSeries::where('system_key', $type)->value('id'), 'document_type' => $type,
            'status' => 'issued', 'number' => 'XML '.++$this->sequence, 'currency' => 'PLN', 'issue_date' => '2026-09-01', 'sale_date' => '2026-08-31',
            'order_reference_snapshot' => '000501', 'order_snapshot' => ['id' => '000501', 'source' => 'prestashop', 'external_id' => '000909'],
            'buyer_name_snapshot' => 'Nabywca fikcyjny', 'buyer_tax_id_snapshot' => '0012345678',
            'buyer_snapshot' => ['name' => 'Nabywca fikcyjny', 'tax_id' => '0012345678', 'street' => 'Testowa', 'building_number' => '2', 'apartment_number' => '0',
                'postal_code' => '00-001', 'city' => 'Miasto', 'province' => 'Testowe', 'country_code' => 'PL', 'country_name' => 'Polska'],
            'seller_tax_id_snapshot' => '1234563218', 'seller_snapshot' => ['name' => 'Sprzedawca fikcyjny', 'street' => 'Inna', 'building_number' => '3',
                'postal_code' => '00-002', 'city' => 'Miasto', 'tax_id' => '1234563218', 'bdo' => '000001', 'bank_account' => 'SECRET_BANK'],
            'recipient_snapshot' => ['country_code' => 'DE'], 'payment_snapshot' => ['effective_payment_method' => 'Gotówka historyczna'],
        ], app(InvoiceTotalsCalculator::class)->calculateEditedDocument($states, '0.00'), $attributes));
        foreach ($states as $index => $state) {
            $invoice->items()->create(array_replace($state, ['position' => $index + 1]));
        }

        return $invoice;
    }

    private function correction(array $before, array $after, array $attributes = []): Invoice
    {
        $pairs = [];
        foreach ($before as $i => $state) {
            $pairs[] = ['correction_before_snapshot' => $state, 'correction_after_snapshot' => $after[$i]];
        }
        $totals = app(CorrectionTotalsCalculator::class)->calculate($pairs);
        $invoice = $this->invoice(array_replace([
            'document_type' => 'correction', 'total_net' => $totals['difference']['net'], 'total_vat' => $totals['difference']['vat'],
            'total_gross' => $totals['difference']['gross'], 'tax_summary_snapshot' => $totals['difference']['tax_summary_snapshot'],
            'correction_totals_snapshot' => ['source_invoice' => ['number' => 'SOURCE FAKE']] + $totals,
        ], $attributes), $after);
        foreach ($invoice->items()->orderBy('position')->get() as $i => $item) {
            $item->update($pairs[$i]);
        }

        return $invoice;
    }

    private function accepted(Invoice $invoice, string $environment): void
    {
        DB::table('ksef_invoice_submissions')->insert([
            'invoice_id' => $invoice->id, 'environment' => $environment, 'attempt_number' => 1, 'status' => 'accepted', 'schema_id' => 'FA(3)',
            'generated_at' => '2026-09-01 12:00:00', 'seller_nip' => '1234563218', 'context_nip' => '1234563218', 'payload_xml' => 'UNREADABLE_FAKE',
            'invoice_hash' => base64_encode(hash('sha256', 'FAKE_XML', true)), 'invoice_size' => 8, 'invoicing_mode' => 'Online',
            'ksef_number' => '1234563218-20260901-000000000001-CA', 'acquisition_date' => '2026-09-01 12:00:00',
        ]);
    }
}
