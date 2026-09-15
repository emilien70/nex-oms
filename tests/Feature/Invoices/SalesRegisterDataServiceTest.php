<?php

namespace Tests\Feature\Invoices;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\Services\SalesRegisterDocumentReader;
use Modules\Invoices\Services\SalesRegisterSummaryService;
use Modules\Invoices\Services\SalesRegisterValues;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesRegisterDataServiceTest extends TestCase
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
    }

    public function test_selection_uses_issued_type_only_and_includes_inactive_series_without_order(): void
    {
        $hidden = InvoiceSeries::create(['document_type' => 'invoice', 'name' => 'Hidden test series', 'number_format' => 'H %N/%Y', 'is_active' => false]);
        $a = $this->invoice(['invoice_series_id' => $hidden->id, 'issued_at' => null, 'paid_amount' => '0.00']);
        $b = $this->invoice(['document_type' => 'correction'], [$this->group('-10.00', '-2.30', '-12.30')]);
        $this->invoice(['status' => 'draft']);
        $this->invoice(['document_type' => 'proforma']);
        $report = $this->period(['series_ids' => [$hidden->id, $this->series('correction')]]);
        $this->assertSame([$a->id, $b->id], array_column($report['records'], 'id'));
        $this->assertSame('110.70', $report['summaries']['currencies']['PLN']['totals']['gross']);
        Http::assertNothingSent();
    }

    public function test_period_boundaries_sale_dates_and_other_filters_are_combined(): void
    {
        $a = $this->invoice(['issue_date' => '2026-08-01', 'sale_date' => '2026-07-31', 'currency' => 'DEM']);
        $b = $this->invoice(['issue_date' => '2026-08-31', 'sale_date' => '2026-08-01', 'currency' => 'DEM']);
        $this->invoice(['issue_date' => '2026-09-01', 'currency' => 'DEM']);
        $this->invoice(['issue_date' => '2026-07-31', 'currency' => 'DEM']);
        $this->invoice(['currency' => 'EUR']);
        $report = $this->period(['month' => '2026-01', 'issue_from' => '2026-08-01', 'issue_to' => '2026-08-31',
            'sale_from' => '2026-07-31', 'sale_to' => '2026-08-01', 'currency' => 'DEM', 'country' => 'DE', 'tax_id_presence' => 'with']);
        $this->assertSame([$a->id, $b->id], array_column($report['records'], 'id'));
        $this->assertSame('DEM', $report['records'][0]['currency']);
        $this->assertFalse($report['summaries']['combined_pln']['coverage']['totals']['complete']);
    }

    public function test_explicit_ids_are_exact_and_reject_missing_or_ineligible_documents(): void
    {
        $a = $this->invoice(['issue_date' => '2025-01-01']);
        $this->invoice();
        $this->assertSame([$a->id], array_column($this->selected([$a->id, $a->id])['records'], 'id'));
        $draft = $this->invoice(['status' => 'draft']);
        $proforma = $this->invoice(['document_type' => 'proforma']);
        foreach ([$draft->id, $proforma->id, 999999] as $invalid) {
            try {
                $this->selected([$a->id, $invalid]);
                $this->fail('Invalid selection accepted.');
            } catch (InvoiceDomainException $exception) {
                $this->assertSame('sales_register_documents_invalid', $exception->errorCode());
                $this->assertSame([$invalid], $exception->metadata()['document_ids']);
            }
        }
    }

    public function test_invalid_series_do_not_expand_scope(): void
    {
        foreach ([999999, $this->series('proforma')] as $id) {
            try {
                $this->period(['series_ids' => [$id]]);
                $this->fail('Invalid series accepted.');
            } catch (InvoiceDomainException $exception) {
                $this->assertSame('sales_register_series_invalid', $exception->errorCode());
                $this->assertSame([$id], $exception->metadata()['series_ids']);
            }
        }
    }

    public function test_full_range_crosses_batch_boundary_in_stable_order_without_n_plus_one(): void
    {
        for ($i = 0; $i < SalesRegisterDataService::BATCH_SIZE + 5; $i++) {
            $this->invoice(['number' => sprintf('R %04d', 400 - $i)]);
        }
        DB::enableQueryLog();
        $report = $this->period();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(205, $report['records']);
        $this->assertCount(205, array_unique(array_column($report['records'], 'id')));
        $this->assertSame(range(1, 205), array_column($report['records'], 'ordinal'));
        $numbers = array_column($report['records'], 'number');
        $sorted = $numbers;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $numbers);
        $this->assertLessThan(12, count($queries));
        $this->assertSame('25215.00', $report['summaries']['currencies']['PLN']['totals']['gross']);
    }

    public function test_buyer_filter_and_row_use_same_snapshot_after_order_change_and_deletion(): void
    {
        $order = Order::create(['source' => 'manual', 'status' => 'new', 'currency' => 'PLN', 'billing_name' => 'Unrelated live name']);
        $invoice = $this->invoice(['order_id' => $order->id]);
        $order->update(['billing_tax_id' => null, 'billing_country_code' => 'PL']);
        $before = $this->period(['country' => 'DE', 'tax_id_presence' => 'with']);
        $order->delete();
        $after = $this->period(['country' => 'DE', 'tax_id_presence' => 'with']);
        $this->assertSame($before, $after);
        $this->assertSame('DE-FAKE-ID', $after['records'][0]['buyer']['tax_id']);
        $this->assertSame('Fixture company', $after['records'][0]['buyer']['name']);
        $this->assertSame([$invoice->id], $after['summaries']['countries']['DE']['currencies']['PLN']['coverage']['totals']['included_ids']);
    }

    public function test_missing_empty_and_conflicting_tax_snapshots_have_explicit_meanings(): void
    {
        $legacy = $this->invoice(['buyer_snapshot' => ['country_code' => 'DE']]);
        $empty = $this->invoice(['buyer_snapshot' => ['tax_id' => '', 'name' => 'Fixture company'], 'buyer_tax_id_snapshot' => null]);
        $conflict = $this->invoice(['buyer_snapshot' => ['tax_id' => '', 'name' => 'Fixture company']]);
        $report = $this->selected([$legacy->id, $empty->id, $conflict->id]);
        $this->assertSame(['legacy', 'empty', 'conflict'], array_column(array_column($report['records'], 'buyer'), 'tax_id_state'));
        $this->assertSame('DE-FAKE-ID', $report['records'][0]['buyer']['tax_id']);
        $this->assertNull($report['records'][2]['buyer']['tax_id']);
        $this->assertSame([$empty->id], array_column($this->period(['tax_id_presence' => 'without'])['records'], 'id'));
        $this->assertSame([$legacy->id], array_column($this->period(['tax_id_presence' => 'with'])['records'], 'id'));
        $this->assertArrayHasKey('unknown', $report['summaries']['countries']);
        $this->assertContains('sales_register_buyer_tax_id_conflict', array_column($report['warnings'], 'code'));
    }

    public function test_one_document_with_multiple_vat_groups_is_counted_once(): void
    {
        $invoice = $this->invoice([], [
            $this->group('10.00', '0.00', '10.00', null, 'zw'),
            $this->group('20.00', '0.00', '20.00', '0.00'),
            $this->group('100.00', '23.00', '123.00'),
            $this->group('100.00', '8.00', '108.00', '8.00'),
        ]);
        $report = $this->selected([$invoice->id]);
        $this->assertCount(1, $report['records']);
        $this->assertSame('23%, 8%, 0%, ZW', $report['records'][0]['vat_labels']);
        $this->assertCount(4, $report['records'][0]['vat_groups']);
        $this->assertSame('261.00', $report['summaries']['combined_pln']['totals']['gross']);
    }

    public function test_invalid_vat_breakdown_preserves_valid_original_totals(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['tax_summary_snapshot' => [$this->group('1.00', '0.23', '1.23')]]);
        $report = $this->selected([$invoice->id]);
        $this->assertTrue($report['records'][0]['completeness']['original']);
        $this->assertFalse($report['records'][0]['completeness']['vat']);
        $this->assertSame('123.00', $report['summaries']['combined_pln']['totals']['gross']);
        $this->assertSame([$invoice->id], $report['summaries']['combined_pln']['coverage']['vat']['excluded_ids']);
    }

    #[DataProvider('invalidMoney')]
    public function test_absent_or_invalid_money_is_never_zero(mixed $value): void
    {
        $invoice = $this->invoice();
        $invoice->load('items', 'corrections');
        $raw = $invoice->getAttributes();
        $raw['total_net'] = $value;
        $invoice->setRawAttributes($raw);
        $reader = app(SalesRegisterDocumentReader::class);
        $row = $reader->read($invoice, $reader->buyer($invoice), ['number' => null, 'warnings' => []]);
        $this->assertNull($row['totals']);
        $this->assertFalse($row['completeness']['original']);
        $this->assertContains('sales_register_totals_invalid', array_column($row['warnings'], 'code'));
    }

    public static function invalidMoney(): array
    {
        return [[null], [''], ['broken'], [false], [[]], ['100.001']];
    }

    public function test_corrections_use_deltas_and_related_documents_never_expand_selection(): void
    {
        $source = $this->invoice(['issue_date' => '2026-07-01']);
        $negative = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id], [$this->group('-10.00', '-2.30', '-12.30')]);
        $positive = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id, 'previous_correction_id' => $negative->id], [$this->group('5.00', '1.15', '6.15')]);
        $report = $this->period(['series_ids' => [$this->series('invoice'), $this->series('correction')]]);
        $this->assertSame([$negative->id, $positive->id], array_column($report['records'], 'id'));
        $this->assertSame('-6.15', $report['summaries']['combined_pln']['totals']['gross']);
        $onlySource = $this->selected([$source->id]);
        $this->assertCount(2, $onlySource['records'][0]['related_documents']);
        $this->assertSame('123.00', $onlySource['summaries']['combined_pln']['totals']['gross']);
        $this->assertSame('Fixture source', $report['records'][0]['related_documents'][0]['number']);
    }

    public function test_data_only_foreign_correction_is_valid_without_conversion_but_tax_transfer_is_not(): void
    {
        $zero = $this->invoice(['document_type' => 'correction', 'currency' => 'EUR', 'tax_metadata_snapshot' => ['ksef_correction' => ['version' => 1]]], [$this->group('0.00', '0.00', '0.00')]);
        $transfer = $this->invoice(['document_type' => 'correction', 'currency' => 'EUR'], [
            $this->group('-10.00', '0.00', '-10.00', '0.00'), $this->group('10.00', '0.00', '10.00', null, 'ZW'),
        ]);
        $report = $this->selected([$zero->id, $transfer->id]);
        $this->assertTrue($report['records'][0]['completeness']['pln']);
        $this->assertSame('no_financial_effect', $report['records'][0]['pln']['source']);
        $this->assertFalse($report['records'][1]['completeness']['pln']);
        $this->assertSame('0%, ZW', $report['records'][1]['vat_labels']);
        $this->assertSame([$transfer->id], $report['summaries']['combined_pln']['coverage']['totals']['excluded_ids']);
    }

    #[DataProvider('shippingChanges')]
    public function test_shipping_corrections_use_both_states_and_group_identity(array $before, array $after, string $gross, int $groupCount): void
    {
        $invoice = $this->invoice(['document_type' => 'correction']);
        $invoice->items()->update([
            'line_type' => $after['line_type'], 'correction_before_snapshot' => json_encode($before),
            'correction_after_snapshot' => json_encode($after),
        ]);
        $report = $this->selected([$invoice->id]);
        $shipping = $report['records'][0]['shipping'];
        $this->assertSame($gross, $shipping['totals']['gross']);
        $this->assertCount($groupCount, $shipping['groups']);
        $this->assertSame('123.00', $report['summaries']['combined_pln']['totals']['gross']);
    }

    public static function shippingChanges(): array
    {
        $shipping = ['line_type' => 'shipping', 'vat_rate' => '23.00', 'vat_code' => null, 'total_net' => '10.00', 'total_vat' => '2.30', 'total_gross' => '12.30'];
        $more = array_replace($shipping, ['total_net' => '20.00', 'total_vat' => '4.60', 'total_gross' => '24.60']);

        return [
            [$shipping, $more, '12.30', 1], [$more, $shipping, '-12.30', 1],
            [$shipping, array_replace($shipping, ['line_type' => 'product']), '-12.30', 1],
            [array_replace($shipping, ['line_type' => 'product']), $shipping, '12.30', 1],
            [$shipping, array_replace($shipping, ['vat_rate' => '8.00', 'total_net' => '11.39', 'total_vat' => '0.91']), '0.00', 2],
        ];
    }

    public function test_historical_pln_totals_are_read_and_shipping_is_converted_per_document_and_group(): void
    {
        $a = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion('EUR', '4.34201234', '434.20', '99.87', '534.07')]);
        $a->items()->first()->update(['line_type' => 'shipping', 'total_net' => '0.01', 'total_vat' => '0.01', 'total_gross' => '0.02']);
        $b = $this->invoice(['currency' => 'USD', 'tax_metadata_snapshot' => $this->conversion('USD', '3.0000', '300.00', '69.00', '369.00')]);
        $b->items()->first()->update(['line_type' => 'shipping', 'total_net' => '1.00', 'total_vat' => '0.23', 'total_gross' => '1.23']);
        $pln = $this->invoice();
        $report = $this->selected([$a->id, $b->id, $pln->id]);
        $this->assertSame('4.34201234', $report['records'][0]['exchange_rate']['rate']);
        $this->assertSame('534.07', $report['records'][0]['pln']['totals']['gross']);
        $this->assertSame('0.08', $report['records'][0]['shipping_pln']['totals']['gross']);
        $this->assertSame('computed_from_stored_items_and_historical_rate', $report['records'][0]['shipping_pln']['source']);
        $this->assertSame('903.07', $report['summaries']['foreign_in_pln']['totals']['gross']);
        $this->assertSame('1026.07', $report['summaries']['combined_pln']['totals']['gross']);
        $this->assertSame('3.77', $report['summaries']['combined_pln']['shipping']['totals']['gross']);
        $this->assertCount(3, $report['summaries']['countries']['DE']['currencies']);
        Http::assertNothingSent();
    }

    public function test_incomplete_shipping_pln_does_not_invalidate_stored_document_pln(): void
    {
        $invoice = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion('EUR', '4.3420', '434.20', '99.87', '534.07')]);
        $invoice->items()->delete();
        $row = $this->selected([$invoice->id])['records'][0];
        $this->assertTrue($row['completeness']['pln']);
        $this->assertFalse($row['completeness']['shipping_pln']);
        $this->assertNull($row['shipping_pln']);
    }

    public function test_missing_and_corrupt_conversion_keep_original_values_and_track_excluded_ids(): void
    {
        $a = $this->invoice(['currency' => 'EUR']);
        $b = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => ['currency_conversion' => ['rate' => '4.20']]]);
        $report = $this->selected([$a->id, $b->id]);
        $this->assertSame('246.00', $report['summaries']['currencies']['EUR']['totals']['gross']);
        $this->assertNull($report['summaries']['combined_pln']['totals']);
        $this->assertSame([$a->id, $b->id], $report['summaries']['combined_pln']['coverage']['totals']['excluded_ids']);
        $this->assertSame(2, $report['selection']['record_count']);
    }

    #[DataProvider('corruptConversions')]
    public function test_malformed_conversion_fields_do_not_abort_the_report(string $path, mixed $value): void
    {
        $metadata = $this->conversion('EUR', '4.00', '400.00', '92.00', '492.00');
        data_set($metadata, $path, $value);
        $invoice = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $metadata]);
        $report = $this->selected([$invoice->id]);
        $this->assertNull($report['records'][0]['pln']);
        $this->assertSame('123.00', $report['summaries']['currencies']['EUR']['totals']['gross']);
        $this->assertSame([$invoice->id], $report['summaries']['combined_pln']['coverage']['totals']['excluded_ids']);
    }

    public static function corruptConversions(): array
    {
        return [
            ['converted_tax_summary.groups.0.vat_code', []],
            ['converted_tax_summary.groups.0.vat_rate', []],
            ['converted_tax_summary.groups.0.net', null],
            ['converted_tax_summary.total_vat', null],
            ['currency_conversion.rate', []],
            ['currency_conversion.effective_date', '2026-02-30'],
            ['currency_conversion.source_currency', 'USD'],
        ];
    }

    public function test_empty_report_has_zero_documents_and_complete_zero_totals(): void
    {
        $report = $this->period();
        $this->assertSame(0, $report['selection']['record_count']);
        $this->assertSame([], $report['records']);
        $this->assertSame('0.00', $report['summaries']['combined_pln']['totals']['gross']);
        $this->assertTrue($report['summaries']['completeness']['original']['complete']);
    }

    public function test_unknown_amounts_currency_number_and_dates_are_not_replaced_with_defaults(): void
    {
        $invoice = $this->invoice(['currency' => '', 'number' => null, 'issue_date' => null, 'sale_date' => null]);
        DB::table('invoices')->where('id', $invoice->id)->update(['total_net' => 'corrupt']);
        $report = $this->selected([$invoice->id]);
        $row = $report['records'][0];
        $this->assertNull($row['number']);
        $this->assertNull($row['issue_date']);
        $this->assertNull($row['sale_date']);
        $this->assertNull($row['currency']);
        $this->assertNull($report['summaries']['currencies']['unknown']['totals']);
        $this->assertSame([$invoice->id], $report['summaries']['combined_pln']['coverage']['totals']['excluded_ids']);
        $this->assertSame(1, $report['selection']['record_count']);
        $this->assertSame(0, $report['summaries']['foreign_in_pln']['document_count']);
    }

    public function test_buyer_name_conflict_and_invalid_nested_values_are_not_hidden_by_scalar_fallback(): void
    {
        $conflict = $this->invoice(['buyer_snapshot' => ['company_name' => 'Different fixture company', 'tax_id' => []]]);
        $invalid = $this->invoice(['buyer_snapshot' => ['company_name' => [], 'name' => 'Fixture company']]);
        $rows = $this->selected([$conflict->id, $invalid->id])['records'];
        $this->assertSame('conflict', $rows[0]['buyer']['name_state']);
        $this->assertSame('invalid', $rows[0]['buyer']['tax_id_state']);
        $this->assertSame('invalid', $rows[1]['buyer']['name_state']);
        $this->assertNull($rows[0]['buyer']['name']);
        $this->assertNull($rows[1]['buyer']['name']);
    }

    public function test_summary_does_not_apply_single_document_amount_limit(): void
    {
        $invoice = $this->invoice();
        $row = $this->selected([$invoice->id])['records'][0];
        $group = array_replace($row['vat_groups'][0], ['net' => '999999999999999.99', 'vat' => '0.00', 'gross' => '999999999999999.99']);
        $row['totals'] = ['net' => $group['net'], 'vat' => $group['vat'], 'gross' => $group['gross']];
        $row['vat_groups'] = [$group];
        $second = array_replace($row, ['id' => $invoice->id + 1]);
        $summary = app(SalesRegisterSummaryService::class)->summarize([$row, $second]);
        $this->assertSame('1999999999999999.98', $summary['currencies']['PLN']['totals']['gross']);
        $this->assertSame('1999999999999999.98', $summary['currencies']['PLN']['vat_groups'][0]['gross']);
    }

    public function test_negative_foreign_shipping_uses_own_saved_rate_and_not_source_invoice_conversion(): void
    {
        $source = $this->invoice(['currency' => 'EUR', 'tax_metadata_snapshot' => $this->conversion('EUR', '4.00', '400.00', '92.00', '492.00')]);
        $correction = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id, 'currency' => 'EUR',
            'tax_metadata_snapshot' => $this->conversion('EUR', '3.33333333', '-33.33', '-7.67', '-41.00')],
            [$this->group('-10.00', '-2.30', '-12.30')]);
        $before = ['line_type' => 'shipping', 'vat_rate' => '23.00', 'vat_code' => null, 'total_net' => '10.00', 'total_vat' => '2.30', 'total_gross' => '12.30'];
        $after = array_replace($before, ['total_net' => '0.00', 'total_vat' => '0.00', 'total_gross' => '0.00']);
        $correction->items()->first()->update(['correction_before_snapshot' => $before, 'correction_after_snapshot' => $after]);
        $row = $this->selected([$correction->id])['records'][0];
        $this->assertSame('-41.00', $row['shipping_pln']['totals']['gross']);
        $this->assertSame('3.33333333', $row['exchange_rate']['rate']);
        $correction->update(['tax_metadata_snapshot' => null]);
        $missing = $this->selected([$correction->id])['records'][0];
        $this->assertNull($missing['pln']);
        $this->assertNull($missing['shipping_pln']);
        $this->assertSame('-12.30', $missing['shipping']['totals']['gross']);
    }

    public function test_missing_correction_snapshot_is_visible_and_conflicting_difference_is_not_counted(): void
    {
        $invoice = $this->invoice(['document_type' => 'correction']);
        $invoice->update(['correction_totals_snapshot' => null]);
        $row = $this->selected([$invoice->id])['records'][0];
        $this->assertContains('sales_register_correction_difference_missing', array_column($row['warnings'], 'code'));
        $this->assertSame('123.00', $row['totals']['gross']);
        $invoice->update(['correction_totals_snapshot' => ['difference' => ['net' => '1.00', 'vat' => '0.23', 'gross' => '1.23']]]);
        $report = $this->selected([$invoice->id]);
        $this->assertNull($report['records'][0]['totals']);
        $this->assertFalse($report['summaries']['completeness']['original']['complete']);
    }

    public function test_build_only_reads_database_and_dispatches_nothing(): void
    {
        $invoice = $this->invoice();
        $before = $invoice->fresh()->toArray();
        DB::enableQueryLog();
        $this->selected([$invoice->id]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        $this->assertSame($before, $invoice->fresh()->toArray());
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function period(array $changes = []): array
    {
        return app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forPeriod(array_replace([
            'month' => '2026-08', 'series_ids' => [$this->series('invoice')], 'include_ksef' => false,
        ], $changes)));
    }

    private function selected(array $ids): array
    {
        return app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forDocuments($ids, false));
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
            'invoice_series_id' => $this->series($type), 'series_name_snapshot' => 'Historical fixture series',
            'document_type' => $type, 'status' => 'issued', 'number' => 'R '.++$this->sequence,
            'issue_date' => '2026-08-01', 'sale_date' => '2026-08-01',
            'buyer_name_snapshot' => 'Fixture company', 'buyer_tax_id_snapshot' => 'DE-FAKE-ID',
            'buyer_snapshot' => ['name' => 'Fixture person', 'company_name' => 'Fixture company', 'tax_id' => 'DE-FAKE-ID',
                'street' => 'Fixture street', 'city' => 'Fixture city', 'country_code' => 'DE', 'country_name' => 'Niemcy'],
            'currency' => 'PLN', 'total_net' => $totals['net'], 'total_vat' => $totals['vat'], 'total_gross' => $totals['gross'],
            'tax_summary_snapshot' => $groups, 'tax_metadata_snapshot' => [],
            'correction_totals_snapshot' => $type === 'correction' ? [
                'source_invoice' => ['number' => 'Fixture source', 'issue_date' => '2026-07-01'],
                'difference' => $totals + ['tax_summary_snapshot' => $groups],
            ] : null,
        ], $attributes));
        $item = ['line_type' => 'product', 'position' => 1, 'name' => 'Fixture item', 'vat_rate' => '23.00', 'vat_code' => null,
            'total_net' => '100.00', 'total_vat' => '23.00', 'total_gross' => '123.00'];
        $invoice->items()->create($item + ['correction_before_snapshot' => $item, 'correction_after_snapshot' => $item]);

        return $invoice;
    }

    private function group(string $net = '100.00', string $vat = '23.00', string $gross = '123.00', ?string $rate = '23.00', ?string $code = null): array
    {
        return ['vat_rate' => $rate, 'vat_code' => $code, 'net' => $net, 'vat' => $vat, 'gross' => $gross];
    }

    private function conversion(string $currency, string $rate, string $net, string $vat, string $gross): array
    {
        return [
            'currency_conversion' => ['version' => 1, 'source' => 'NBP', 'source_currency' => $currency, 'target_currency' => 'PLN',
                'table_type' => 'A', 'table_number' => 'FAKE/A/NBP/2026', 'effective_date' => '2026-07-31', 'reference_date' => '2026-08-01',
                'rate' => $rate, 'rate_rule' => 'vat_art_31a_standard_v1', 'rounding_mode' => 'half_up', 'result_scale' => 2],
            'converted_tax_summary' => ['currency' => 'PLN', 'groups' => [$this->group($net, $vat, $gross)],
                'total_net' => $net, 'total_vat' => $vat, 'total_gross' => $gross],
        ];
    }
}
