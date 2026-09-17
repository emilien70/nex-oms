<?php

namespace Tests\Feature\Invoices;

use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\Services\SalesRegisterValues;
use Modules\Invoices\Services\SalesRegisterXlsxExporter;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\Invoices\IsolatesSalesRegisterFiles;
use Tests\TestCase;
use ZipArchive;

class SalesRegisterXlsxTest extends TestCase
{
    use IsolatesSalesRegisterFiles;
    use RefreshDatabase;

    private int $sequence = 0;

    private array $books = [];

    private const HEADERS = ['Lp.', 'Typ', 'Pełny numer', 'Nabywca', 'Adres', 'Kod pocztowy', 'Miasto', 'NIP',
        'Data wystawienia', 'Data sprzedaży', 'Netto', 'VAT', 'Kwota VAT', 'Brutto', 'Sposób płatności',
        'Waluta', 'Kurs waluty', 'Kraj', 'Dokument powiązany', 'Numer KSeF', 'Data autoryzacji w KSeF'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
        config(['app.timezone' => 'Europe/Warsaw']);
    }

    protected function tearDown(): void
    {
        foreach ($this->books as $book) {
            $book->disconnectWorksheets();
        }
        parent::tearDown();
    }

    public function test_native_download_has_exact_columns_types_dates_and_private_headers(): void
    {
        $invoice = $this->invoice(['number' => '0000123']);
        $this->accepted($invoice, '2026-09-01 23:30:00');
        $response = $this->post(route('invoices.sales-register.export'), $this->selected([$invoice->id], true))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('rejestr_sprzedazy_wybrane.xlsx');
        $this->assertStringNotContainsString('charset', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $book = $this->read($response->baseResponse);
        $this->assertSame(['Rejestr sprzedaży'], $book->getSheetNames());
        $sheet = $book->getActiveSheet();
        $this->assertSame(self::HEADERS, $sheet->rangeToArray('A1:U1')[0]);
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('U', $sheet->getHighestDataColumn());
        foreach (['B2' => 'Faktura', 'C2' => '0000123', 'D2' => 'Firma fikcyjna & <Żółć>', 'E2' => 'Testowa 1/2',
            'F2' => '00-001', 'G2' => 'Miasto', 'H2' => '0012345678', 'I2' => '2026-09-01', 'J2' => '2026-08-31',
            'O2' => 'Przelew historyczny', 'P2' => 'PLN', 'Q2' => '1,000000', 'R2' => 'Polska', 'S2' => '-',
            'T2' => '1234563218-20260901-000000000001-CA', 'U2' => '02.09.2026'] as $cell => $value) {
            $this->assertSame($value, $sheet->getCell($cell)->getValue(), $cell);
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), $cell);
        }
        foreach (['K2' => '100.00', 'L2' => '23.00', 'M2' => '23.00', 'N2' => '123.00'] as $cell => $amount) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType());
            $this->assertSame($amount, sprintf('%.2F', $sheet->getCell($cell)->getValue()));
            $this->assertSame('0.00', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }
        $this->assertSame(1, $sheet->getCell('A2')->getValue());
        $this->assertSame('0', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_multiple_vat_groups_corrections_and_zero_stay_one_row_per_document(): void
    {
        $source = $this->invoice([], [$this->group(), $this->group('10.00', '0.80', '10.80', '8.00'), $this->group('2.00', '0.00', '2.00', null, 'ZW')]);
        $negative = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id], [$this->group('-10.00', '-2.30', '-12.30')]);
        $zero = $this->invoice([], [$this->group('0.00', '0.00', '0.00', '0.00')]);
        $code = $this->invoice([], [$this->group('1.00', '0.00', '1.00', null, 'ZW')]);
        $sheet = $this->export($this->selected([$source->id, $negative->id, $zero->id, $code->id]))->getActiveSheet();
        $this->assertSame(5, $sheet->getHighestDataRow());
        $this->assertSame('23,00; 8,00; ZW', $sheet->getCell('L2')->getValue());
        $this->assertSame('135.80', sprintf('%.2F', $sheet->getCell('N2')->getValue()));
        $this->assertSame('Korekta', $sheet->getCell('B3')->getValue());
        $this->assertSame(-12.3, $sheet->getCell('N3')->getValue());
        $this->assertSame('Faktura FIX SOURCE', $sheet->getCell('S3')->getValue());
        $this->assertSame('Korekta FIX 2', $sheet->getCell('S2')->getValue());
        foreach (['K4', 'L4', 'M4', 'N4'] as $cell) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType());
            $this->assertEquals(0, $sheet->getCell($cell)->getValue());
        }
        $this->assertSame('ZW', $sheet->getCell('L5')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('L5')->getDataType());
    }

    public function test_foreign_rate_keeps_precision_and_missing_values_remain_blank_with_notes(): void
    {
        $foreign = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion()]);
        $missing = $this->invoice(['currency' => 'EUR', 'payment_snapshot' => [], 'sale_date' => null]);
        $this->accepted($missing, null);
        DB::table('invoices')->where('id', $missing->id)->update(['total_net' => 'BAD', 'total_vat' => 'BAD', 'total_gross' => 'BAD']);
        $book = $this->export($this->selected([$foreign->id, $missing->id], true));
        $this->assertSame(['Rejestr sprzedaży', 'Uwagi'], $book->getSheetNames());
        $sheet = $book->getActiveSheet();
        $this->assertSame('4,00000000001234567890', $sheet->getCell('Q2')->getValue());
        foreach (['J3', 'K3', 'M3', 'N3', 'O3', 'Q3', 'U3'] as $cell) {
            $this->assertNull($sheet->getCell($cell)->getValue(), $cell);
        }
        $notes = json_encode($book->getSheetByName('Uwagi')->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Brak zapisanej metody płatności', $notes);
        $this->assertStringContainsString('Brak poprawnego historycznego kursu', $notes);
        $this->assertStringContainsString('Brak prawidłowej daty autoryzacji', $notes);
    }

    #[DataProvider('payments')]
    public function test_payment_uses_only_persisted_effective_method(array $payment, ?string $expected, ?string $warning): void
    {
        $invoice = $this->invoice(['payment_snapshot' => $payment + ['order_payment_method' => 'NOT THE METHOD', 'payment_status' => 'paid']]);
        $book = $this->export($this->selected([$invoice->id]));
        $this->assertSame($expected, $book->getActiveSheet()->getCell('O2')->getValue());
        $this->assertSame($warning === null ? 1 : 2, $book->getSheetCount());
        if ($warning !== null) {
            $this->assertStringContainsString($warning, json_encode($book->getSheetByName('Uwagi')->toArray(), JSON_UNESCAPED_UNICODE));
        }
    }

    public static function payments(): array
    {
        return [
            'historical' => [['effective_payment_method' => 'Gotówka'], 'Gotówka', null],
            'disabled' => [['effective_payment_method' => null], null, null],
            'empty' => [['effective_payment_method' => ''], null, null],
            'whitespace' => [['effective_payment_method' => '  '], null, null],
            'missing' => [[], null, 'Brak zapisanej metody'],
            'array' => [['effective_payment_method' => ['wrong']], null, 'Nieprawidłowa zapisana metoda'],
            'boolean' => [['effective_payment_method' => false], null, 'Nieprawidłowa zapisana metoda'],
        ];
    }

    public function test_formula_like_texts_are_literal_and_xml_contains_no_active_content(): void
    {
        $invoice = $this->invoice();
        $report = $this->report([$invoice->id]);
        $cases = ['=1+1', '+1+1', '-1+1', '@SUM(1,1)', " \t=1+1", "\n+1+1", 'Żółć & <test>'];
        foreach ($cases as $value) {
            $record = &$report['records'][0];
            foreach (['number', 'payment_method'] as $field) {
                $record[$field] = $value;
            }
            $record['buyer']['name'] = $value;
            $record['buyer']['tax_id'] = $value;
            $record['buyer']['address']['postal_code'] = $value;
            unset($record);
            $book = $this->read(app(SalesRegisterXlsxExporter::class)->download($report, SalesRegisterFilters::forDocuments([$invoice->id], false)));
            foreach (['C2', 'D2', 'F2', 'H2', 'O2'] as $cell) {
                $this->assertSame($value, $book->getActiveSheet()->getCell($cell)->getValue());
                $this->assertSame(DataType::TYPE_STRING, $book->getActiveSheet()->getCell($cell)->getDataType());
            }
        }
    }

    public function test_precision_is_checked_before_numeric_conversion_and_exact_text_is_reported(): void
    {
        $invoice = $this->invoice();
        $report = $this->report([$invoice->id]);
        foreach (['9999999999999.99', '-1234567890123.45', '10000000000000.01', '12345678901234567890.12'] as $amount) {
            $report['records'][0]['totals'] = ['net' => $amount, 'vat' => '0.00', 'gross' => $amount];
            $book = $this->read(app(SalesRegisterXlsxExporter::class)->download($report, SalesRegisterFilters::forDocuments([$invoice->id], false)));
            $cell = $book->getActiveSheet()->getCell('K2');
            if (strlen(ltrim(str_replace(['-', '.'], '', $amount), '0')) > 15) {
                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                $this->assertSame($amount, $cell->getValue());
                $this->assertStringContainsString('zachować dokładność', json_encode($book->getSheetByName('Uwagi')->toArray(), JSON_UNESCAPED_UNICODE));
            } else {
                $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
                $this->assertSame($amount, sprintf('%.2F', $cell->getValue()));
            }
        }
    }

    public function test_period_and_ids_over_batch_limit_match_html_and_do_not_write_or_read_ksef(): void
    {
        $ids = [];
        for ($i = 0; $i < 205; $i++) {
            $ids[] = $this->invoice()->id;
        }
        $outside = $this->invoice(['issue_date' => '2026-08-01']);
        $before = DB::table('invoices')->get()->toJson();
        foreach ([$this->period(), $this->selected($ids) + ['page' => 5, 'per_page' => 25, 'month' => 'invalid']] as $payload) {
            $html = $this->post(route('invoices.sales-register.export'), array_replace($payload, ['format' => 'html', 'include_header' => 1, 'include_exchange_rates' => 0]))->assertOk();
            $records = $html->viewData('report')['records'];
            $this->assertEqualsCanonicalizing($ids, array_column($records, 'id'));
            $this->assertNotContains($outside->id, array_column($records, 'id'));
            DB::flushQueryLog();
            DB::enableQueryLog();
            $book = $this->export($payload);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $sheet = $book->getActiveSheet();
            $this->assertSame(206, $sheet->getHighestDataRow());
            $this->assertSame('S', $sheet->getHighestDataColumn());
            $this->assertSame(array_slice(self::HEADERS, 0, 19), $sheet->rangeToArray('A1:S1')[0]);
            foreach ($records as $index => $record) {
                $row = $index + 2;
                $this->assertSame($record['number'], $sheet->getCell('C'.$row)->getValue());
                $this->assertSame($record['ordinal'], $sheet->getCell('A'.$row)->getValue());
                foreach (['K' => 'net', 'M' => 'vat', 'N' => 'gross'] as $column => $field) {
                    $this->assertSame($record['totals'][$field], sprintf('%.2F', $sheet->getCell($column.$row)->getValue()));
                }
            }
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
                $this->assertStringNotContainsString('ksef_', $query['query']);
            }
            $this->assertLessThan(15, count($queries));
        }
        $this->assertSame($before, DB::table('invoices')->get()->toJson());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_empty_report_and_selection_warnings_have_no_fake_document_rows(): void
    {
        $book = $this->export($this->period());
        $this->assertSame(1, $book->getSheetCount());
        $this->assertSame(1, $book->getActiveSheet()->getHighestDataRow());
        $invoice = $this->invoice(['buyer_tax_id_snapshot' => 'CONFLICT']);
        $book = $this->export(array_replace($this->period(), ['tax_id_presence' => 'with']));
        $this->assertSame(1, $book->getActiveSheet()->getHighestDataRow());
        $this->assertSame('ID '.$invoice->id, $book->getSheetByName('Uwagi')->getCell('A2')->getValue());
        $this->assertStringContainsString('Dokument pominięty', $book->getSheetByName('Uwagi')->getCell('C2')->getValue());
    }

    public function test_xlsx_ignores_html_options_and_validation_preserves_format(): void
    {
        $invoice = $this->invoice();
        foreach ([[], ['include_header' => 1, 'include_exchange_rates' => 1], ['include_header' => ['invalid'], 'include_exchange_rates' => 'wrong']] as $options) {
            $book = $this->export($this->selected([$invoice->id]) + $options);
            $this->assertSame(2, $book->getActiveSheet()->getHighestDataRow());
            $this->assertSame(1, $book->getSheetCount());
            $this->assertSame('Lp.', $book->getActiveSheet()->getCell('A1')->getValue());
        }
        $response = $this->post(route('invoices.sales-register.export'), array_replace($this->period(), ['series_ids' => []]))->assertStatus(422);
        $this->assertSame('xlsx', $response->viewData('values')['format']);
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame('xlsx', $xpath->evaluate('string(//input[@name="format" and @checked]/@value)'));
        $this->assertSame('_self', $xpath->evaluate('string(//form[@id="salesRegisterForm"]/@target)'));
        $this->assertSame(2, $xpath->query('//*[@data-html-option and @hidden]//select[@disabled]')->length);
        foreach (['xls', 'csv', 'pdf', 'invoices.sales-register.export'] as $format) {
            $this->post(route('invoices.sales-register.export'), array_replace($this->period(), ['format' => $format]))->assertStatus(422)->assertSee('Nie można wygenerować rejestru.');
        }
        $this->post(route('invoices.sales-register.export'), array_replace($this->period(), ['format' => 'html']))->assertStatus(422);
    }

    public function test_oversized_text_is_a_controlled_html_error_and_cleans_its_temporary_file(): void
    {
        $invoice = $this->invoice(['payment_snapshot' => ['effective_payment_method' => str_repeat('Ż', 32768)]]);
        $before = glob(storage_path('app/private/sales-register-exports/register-*'));
        $this->post(route('invoices.sales-register.export'), $this->selected([$invoice->id]))->assertStatus(422)
            ->assertSee('przekracza limit 32767')->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertSame($before, glob(storage_path('app/private/sales-register-exports/register-*')));
    }

    public function test_row_limit_is_checked_before_writing_and_temp_file_is_removed(): void
    {
        $before = glob(storage_path('app/private/sales-register-exports/register-*'));
        try {
            app(SalesRegisterXlsxExporter::class)->download(['records' => array_fill(0, 1048576, [])], SalesRegisterFilters::forDocuments([1], false));
            $this->fail('Expected controlled row limit.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('sales_register_xlsx_row_limit', $exception->errorCode());
        }
        $this->assertSame($before, glob(storage_path('app/private/sales-register-exports/register-*')));
    }

    public function test_conflicting_ksef_dates_reach_notes_without_dropping_the_number(): void
    {
        $invoice = $this->invoice();
        $this->accepted($invoice, '2026-09-01 12:00:00');
        $duplicate = (array) DB::table('ksef_invoice_submissions')->first();
        unset($duplicate['id']);
        DB::table('ksef_invoice_submissions')->insert(array_replace($duplicate, ['attempt_number' => 2, 'acquisition_date' => '2026-09-02 12:00:00']));
        $book = $this->export($this->selected([$invoice->id], true));
        $this->assertSame('1234563218-20260901-000000000001-CA', $book->getActiveSheet()->getCell('T2')->getValue());
        $this->assertNull($book->getActiveSheet()->getCell('U2')->getValue());
        $this->assertStringContainsString('Sprzeczne daty autoryzacji', $book->getSheetByName('Uwagi')->getCell('C2')->getValue());
    }

    public function test_generation_exception_is_safe_html_and_cleans_only_the_created_file(): void
    {
        $invoice = $this->invoice();
        $before = glob(storage_path('app/private/sales-register-exports/register-*'));
        $this->mock(ResponseFactory::class, function ($mock): void {
            $mock->shouldReceive('download')->once()->andThrow(new \RuntimeException('FAKE_PRIVATE_DETAIL'));
        });
        try {
            app(SalesRegisterXlsxExporter::class)->download($this->report([$invoice->id]), SalesRegisterFilters::forDocuments([$invoice->id], false));
            $this->fail('Expected controlled download failure.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('sales_register_xlsx_failed', $exception->errorCode());
            $this->assertStringNotContainsString('FAKE_PRIVATE_DETAIL', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame($before, glob(storage_path('app/private/sales-register-exports/register-*')));
    }

    public function test_fake_example_can_be_saved_outside_project_for_manual_review(): void
    {
        $invoice = $this->invoice(['payment_snapshot' => ['effective_payment_method' => '=1+1']]);
        $this->accepted($invoice, '2026-09-01 23:30:00');
        $foreign = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion()]);
        $correction = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $invoice->id], [$this->group('-10.00', '-2.30', '-12.30')]);
        $response = $this->post(route('invoices.sales-register.export'), $this->selected([$invoice->id, $foreign->id, $correction->id], true))->assertOk();
        $bytes = $this->bytes($response->baseResponse);
        $directory = getenv('SALES_REGISTER_PREVIEW_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'rejestr-sprzedazy-przyklad.xlsx', $bytes);
        }
        $this->assertSame(4, $this->loadBytes($bytes)->getActiveSheet()->getHighestDataRow());
    }

    private function export(array $payload): Spreadsheet
    {
        $response = $this->post(route('invoices.sales-register.export'), $payload)->assertOk();

        return $this->read($response->baseResponse);
    }

    private function read(BinaryFileResponse $response): Spreadsheet
    {
        return $this->loadBytes($this->bytes($response));
    }

    private function bytes(BinaryFileResponse $response): string
    {
        $path = $response->getFile()->getPathname();
        $this->assertFileExists($path);
        $expected = file_get_contents($path);
        $this->assertSame(realpath(storage_path('app/private/sales-register-exports')), realpath(dirname($path)));
        ob_start();
        try {
            $response->sendContent();
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertFileDoesNotExist($path);
        $this->assertSame($expected, $bytes);
        $this->assertStringStartsWith('PK', $bytes);

        return $bytes;
    }

    private function loadBytes(string $bytes): Spreadsheet
    {
        $path = tempnam($this->exportWorkspace->root.'/scratch', 'rs-xlsx-test-');
        $zip = null;
        $zipOpened = false;
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            $opened = $zip->open($path);
            $zipOpened = $opened === true;
            $this->assertTrue($opened);
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'] as $part) {
                $this->assertNotFalse($zip->locateName($part));
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $this->assertDoesNotMatchRegularExpression('/externalLink|vbaProject|calcChain/', $name);
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $document = new DOMDocument;
                    $this->assertTrue($document->loadXML($zip->getFromIndex($i), LIBXML_NONET));
                    $xpath = new DOMXPath($document);
                    $this->assertSame(0, $xpath->query('//*[local-name()="f" or local-name()="hyperlink" or local-name()="ddeLink"] | //*[@TargetMode="External"] | //*[local-name()="sheet" and @state!="visible"]')->length);
                }
            }
            $zip->close();
            $zipOpened = false;
            $book = (new Xlsx)->load($path);
            $this->books[] = $book;

            return $book;
        } finally {
            if ($zipOpened) {
                $zip->close();
            }
            unlink($path);
        }
    }

    private function selected(array $ids, bool $ksef = false): array
    {
        return ['mode' => 'ids', 'document_ids' => json_encode($ids), 'format' => 'xlsx', 'include_ksef' => (int) $ksef];
    }

    private function period(): array
    {
        return ['mode' => 'period', 'month' => 9, 'year' => 2026, 'series_ids' => InvoiceSeries::whereIn('system_key', ['invoice', 'correction'])->pluck('id')->all(),
            'tax_id_presence' => 'all', 'format' => 'xlsx', 'include_ksef' => 0];
    }

    private function report(array $ids): array
    {
        return app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forDocuments($ids, false));
    }

    private function invoice(array $attributes = [], ?array $groups = null): Invoice
    {
        $type = $attributes['document_type'] ?? 'invoice';
        $groups ??= [$this->group()];
        $totals = app(SalesRegisterValues::class)->sum($groups);
        $invoice = Invoice::create(array_replace([
            'invoice_series_id' => InvoiceSeries::where('system_key', $type)->value('id'), 'document_type' => $type,
            'status' => 'issued', 'number' => 'FIX '.++$this->sequence, 'currency' => 'PLN', 'issue_date' => '2026-09-01', 'sale_date' => '2026-08-31',
            'buyer_name_snapshot' => 'Firma fikcyjna & <Żółć>', 'buyer_tax_id_snapshot' => '0012345678',
            'buyer_snapshot' => ['name' => 'Firma fikcyjna & <Żółć>', 'tax_id' => '0012345678', 'street' => 'Testowa', 'building_number' => '1', 'apartment_number' => '2',
                'postal_code' => '00-001', 'city' => 'Miasto', 'country_code' => 'PL', 'country_name' => 'Polska'],
            'payment_snapshot' => ['effective_payment_method' => 'Przelew historyczny'],
            'total_net' => $totals['net'], 'total_vat' => $totals['vat'], 'total_gross' => $totals['gross'], 'tax_summary_snapshot' => $groups,
            'correction_totals_snapshot' => $type === 'correction' ? ['source_invoice' => ['number' => 'FIX SOURCE'], 'difference' => $totals + ['tax_summary_snapshot' => $groups]] : null,
        ], $attributes));
        $item = ['line_type' => 'product', 'position' => 1, 'name' => 'Fikcyjna pozycja', 'vat_rate' => '23.00', 'total_net' => '100.00', 'total_vat' => '23.00', 'total_gross' => '123.00'];
        $invoice->items()->create($item + ['correction_before_snapshot' => $item, 'correction_after_snapshot' => $item]);

        return $invoice;
    }

    private function group(string $net = '100.00', string $vat = '23.00', string $gross = '123.00', ?string $rate = '23.00', ?string $code = null): array
    {
        return ['vat_rate' => $rate, 'vat_code' => $code, 'net' => $net, 'vat' => $vat, 'gross' => $gross];
    }

    private function conversion(): array
    {
        return [
            'currency_conversion' => ['version' => 1, 'source' => 'NBP', 'source_currency' => 'EUR', 'target_currency' => 'PLN', 'table_type' => 'A', 'table_number' => 'FAKE/A/2026',
                'effective_date' => '2026-08-31', 'reference_date' => '2026-09-01', 'rate' => '4.00000000001234567890', 'rate_rule' => 'vat_art_31a_standard_v1', 'rounding_mode' => 'half_up', 'result_scale' => 2],
            'converted_tax_summary' => ['currency' => 'PLN', 'groups' => [$this->group('400.00', '92.00', '492.00')], 'total_net' => '400.00', 'total_vat' => '92.00', 'total_gross' => '492.00'],
        ];
    }

    private function accepted(Invoice $invoice, ?string $date): void
    {
        $invoice->update(['seller_tax_id_snapshot' => '1234563218', 'seller_snapshot' => ['tax_id' => '1234563218']]);
        DB::table('ksef_invoice_submissions')->insert([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'attempt_number' => 1, 'status' => 'accepted', 'schema_id' => 'FA(3)',
            'generated_at' => '2026-09-01 12:00:00', 'seller_nip' => '1234563218', 'context_nip' => '1234563218', 'payload_xml' => 'UNREADABLE_FAKE',
            'invoice_hash' => base64_encode(hash('sha256', 'FAKE_XML', true)), 'invoice_size' => 8, 'invoicing_mode' => 'Online',
            'ksef_number' => '1234563218-20260901-000000000001-CA', 'acquisition_date' => $date,
        ]);
    }
}
