<?php

namespace Tests\Feature\Invoices;

use App\Models\Currency;
use App\Support\CurrencyCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Services\InvoiceCurrencyConversionService;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceExchangeRateReferenceDateResolver;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Invoices\Services\NbpExchangeRateClient;
use Modules\Invoices\Services\ProformaService;
use Modules\Invoices\ValueObjects\InvoiceCurrencyConversionContext;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Modules\Invoices\ValueObjects\PreparedInvoiceDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceCurrencyIssuingTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nbp.retries' => 0, 'nbp.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    public function test_pln_invoice_does_not_call_nbp_and_keeps_empty_tax_metadata(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame([], $invoice->tax_metadata_snapshot);
        Http::assertNothingSent();
    }

    public function test_foreign_invoice_fetches_before_persistence_and_stores_complete_pln_snapshot(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        Http::fake(function (Request $request) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_items', 0);
            $this->assertDatabaseCount('order_document_slots', 0);
            $this->assertDatabaseCount('invoice_number_counters', 0);
            $this->assertStringContainsString('/A/EUR/', $request->url());

            return Http::response($this->xml('A', 'EUR', '4.3420'));
        });

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $metadata = $invoice->tax_metadata_snapshot;

        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame([
            'version' => 1,
            'source' => 'NBP',
            'source_currency' => 'EUR',
            'target_currency' => 'PLN',
            'table_type' => 'A',
            'table_number' => '137/A/NBP/2026',
            'effective_date' => '2026-07-17',
            'reference_date' => '2026-07-20',
            'rate' => '4.3420',
            'rate_rule' => 'vat_art_31a_standard_v1',
            'rounding_mode' => 'half_up',
            'result_scale' => 2,
        ], $metadata['currency_conversion']);
        $this->assertSame('434.20', $metadata['converted_tax_summary']['total_net']);
        $this->assertSame('99.87', $metadata['converted_tax_summary']['total_vat']);
        $this->assertSame('534.07', $metadata['converted_tax_summary']['total_gross']);
        $this->assertSame('100.00', $invoice->items->first()->total_gross);
        Http::assertSentCount(1);
    }

    public function test_table_b_currency_uses_table_b_without_unit_adjustment(): void
    {
        $this->currency('AFN', 'B');
        $order = $this->createDocumentOrder(['currency' => 'AFN']);
        $this->createDocumentItem($order, ['currency' => 'AFN']);
        Http::fake(['*' => Http::response($this->xml('B', 'AFN', '0.011809'))]);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame('B', $invoice->tax_metadata_snapshot['currency_conversion']['table_type']);
        $this->assertSame('0.011809', $invoice->tax_metadata_snapshot['currency_conversion']['rate']);
        $this->assertSame('1.18', $invoice->tax_metadata_snapshot['converted_tax_summary']['total_net']);
    }

    #[DataProvider('failedResponses')]
    public function test_nbp_failure_leaves_no_document_number_slot_items_or_event(int $status, string $expectedCode): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        Http::fake(['*' => Http::response('', $status)]);

        $this->expectDomainCode($expectedCode, function () use ($order): void {
            app(InvoiceIssuingService::class)->issue(
                $order,
                $this->createDocumentSeries(),
                $this->documentContext(),
            );
        });

        foreach (['invoices', 'invoice_items', 'order_document_slots', 'invoice_number_counters', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    /** @return array<string, array{int, string}> */
    public static function failedResponses(): array
    {
        return [
            'not found' => [404, 'invoice_exchange_rate_not_found'],
            'server unavailable' => [500, 'invoice_exchange_rate_unavailable'],
        ];
    }

    public function test_connection_failure_does_not_consume_number_and_next_success_uses_first_number(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $series = $this->createDocumentSeries();
        Http::fakeSequence()
            ->pushFailedConnection()
            ->push($this->xml('A', 'EUR', '4.3420'));

        $this->expectDomainCode('invoice_exchange_rate_unavailable', function () use ($order, $series): void {
            app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
        });
        foreach (['invoices', 'invoice_items', 'order_document_slots', 'invoice_number_counters', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());

        $this->assertSame(1, $invoice->sequence_number);
        $this->assertSame(1, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_calculation_failure_leaves_no_document_number_slot_items_or_event(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        Http::fake(['*' => Http::response($this->xml('A', 'EUR', '4.3420'))]);

        $failingConversion = new class(app(CurrencyCatalog::class), app(InvoiceExchangeRateReferenceDateResolver::class), app(NbpExchangeRateClient::class), app(InvoiceDecimalCalculator::class), app(InvoiceTaxIdentityNormalizer::class)) extends InvoiceCurrencyConversionService
        {
            public function apply(
                PreparedInvoiceDocument $prepared,
                InvoiceCurrencyConversionContext $context,
                ?NbpExchangeRate $rate,
            ): PreparedInvoiceDocument {
                throw new InvoiceDomainException(
                    'invoice_exchange_rate_calculation_failed',
                    'Nie można przeliczyć podsumowania podatkowego Faktury do PLN.',
                );
            }
        };
        $this->app->instance(InvoiceCurrencyConversionService::class, $failingConversion);

        $this->expectDomainCode('invoice_exchange_rate_calculation_failed', function () use ($order): void {
            app(InvoiceIssuingService::class)->issue(
                $order,
                $this->createDocumentSeries(),
                $this->documentContext(),
            );
        });

        foreach (['invoices', 'invoice_items', 'order_document_slots', 'invoice_number_counters', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_changed_context_is_refetched_outside_transaction_and_latest_rate_is_used(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $calls = 0;
        Http::fake(function () use ($order, &$calls) {
            $calls++;
            if ($calls === 1) {
                $order->update(['purchased_at' => '2026-07-18 10:00:00']);
            }

            return Http::response($this->xml('A', 'EUR', $calls === 1 ? '4.1000' : '4.2000', '2026-07-17'));
        });

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame(2, $calls);
        $this->assertSame('2026-07-18', $invoice->tax_metadata_snapshot['currency_conversion']['reference_date']);
        $this->assertSame('4.2000', $invoice->tax_metadata_snapshot['currency_conversion']['rate']);
        $this->assertSame(1, $invoice->sequence_number);
    }

    public function test_second_context_change_returns_controlled_error_without_partial_data(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $calls = 0;
        Http::fake(function () use ($order, &$calls) {
            $calls++;
            $order->update(['purchased_at' => $calls === 1 ? '2026-07-19 10:00:00' : '2026-07-18 10:00:00']);

            return Http::response($this->xml('A', 'EUR', '4.2000', '2026-07-17'));
        });

        $this->expectDomainCode('invoice_exchange_rate_context_changed', function () use ($order): void {
            app(InvoiceIssuingService::class)->issue(
                $order,
                $this->createDocumentSeries(),
                $this->documentContext(),
            );
        });

        $this->assertSame(2, $calls);
        foreach (['invoices', 'invoice_items', 'order_document_slots', 'invoice_number_counters', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_invoice_fetches_its_own_rate_and_does_not_copy_anything_from_proforma(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;
        $this->assertSame([], $proforma->tax_metadata_snapshot);
        Http::assertNothingSent();

        Http::fake(['*' => Http::response($this->xml('A', 'EUR', '4.3420'))]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame('4.3420', $invoice->tax_metadata_snapshot['currency_conversion']['rate']);
        Http::assertSentCount(1);
    }

    public function test_stored_snapshot_is_not_recalculated_when_catalog_or_remote_rate_changes(): void
    {
        $this->currency('EUR', 'A');
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        Http::fake(['*' => Http::response($this->xml('A', 'EUR', '4.3420'))]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        Http::preventStrayRequests();
        Currency::query()->whereKey('EUR')->update(['nbp_table' => 'B']);
        $reopened = Invoice::query()->findOrFail($invoice->getKey());

        $this->assertSame('A', $reopened->tax_metadata_snapshot['currency_conversion']['table_type']);
        $this->assertSame('4.3420', $reopened->tax_metadata_snapshot['currency_conversion']['rate']);
        Http::assertSentCount(1);
    }

    private function currency(string $code, string $table): void
    {
        Currency::query()->create(['code' => $code, 'name' => $code, 'nbp_table' => $table]);
    }

    private function expectDomainCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Nie zgłoszono błędu {$code}.");
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }

    private function xml(
        string $table,
        string $currency,
        string $mid,
        string $effectiveDate = '2026-07-17',
    ): string {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><ExchangeRatesSeries><Table>{$table}</Table><Code>{$currency}</Code><Rates><Rate><No>137/{$table}/NBP/2026</No><EffectiveDate>{$effectiveDate}</EffectiveDate><Mid>{$mid}</Mid></Rate></Rates></ExchangeRatesSeries>";
    }
}
