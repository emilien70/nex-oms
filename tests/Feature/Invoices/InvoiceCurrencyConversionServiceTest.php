<?php

namespace Tests\Feature\Invoices;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceCurrencyConversionService;
use Modules\Invoices\ValueObjects\InvoiceCurrencyConversionContext;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Modules\Invoices\ValueObjects\PreparedInvoiceDocument;
use Tests\TestCase;

class InvoiceCurrencyConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_context_from_currency_catalog_and_final_document_dates(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);

        $context = app(InvoiceCurrencyConversionService::class)->contextFor(
            $this->prepared('EUR', '2026-07-22', '2026-07-20'),
        );

        $this->assertSame('EUR', $context->currency);
        $this->assertSame('A', $context->nbpTable);
        $this->assertSame('2026-07-20', $context->referenceDate);
        $this->assertSame('vat_art_31a_standard_v1', $context->rateRule);
    }

    public function test_pln_returns_empty_metadata_without_conversion(): void
    {
        $prepared = $this->prepared('PLN');
        $service = app(InvoiceCurrencyConversionService::class);
        $context = $service->contextFor($prepared);

        $result = $service->apply($prepared, $context, null);

        $this->assertFalse($context->requiresConversion());
        $this->assertSame([], $result->invoiceAttributes['tax_metadata_snapshot']);
    }

    public function test_converts_each_tax_group_then_sums_groups_and_preserves_nonconverted_values(): void
    {
        $this->currency('EUR', 'A');
        $prepared = $this->prepared('EUR', summary: [
            ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '100.00', 'vat' => '23.00', 'gross' => '123.00'],
            ['vat_rate' => '0.00', 'vat_code' => null, 'net' => '10.00', 'vat' => '0.00', 'gross' => '10.00'],
            ['vat_rate' => null, 'vat_code' => 'ZW', 'net' => '5.00', 'vat' => '0.00', 'gross' => '5.00'],
        ]);
        $context = $this->context('EUR', 'A');
        $rate = $this->rate('EUR', 'A', '4.3420');

        $result = app(InvoiceCurrencyConversionService::class)->apply($prepared, $context, $rate);
        $metadata = $result->invoiceAttributes['tax_metadata_snapshot'];
        $converted = $metadata['converted_tax_summary'];

        $this->assertSame('4.3420', $metadata['currency_conversion']['rate']);
        $this->assertSame('PLN', $converted['currency']);
        $this->assertSame([
            ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '434.20', 'vat' => '99.87', 'gross' => '534.07'],
            ['vat_rate' => '0.00', 'vat_code' => null, 'net' => '43.42', 'vat' => '0.00', 'gross' => '43.42'],
            ['vat_rate' => null, 'vat_code' => 'ZW', 'net' => '21.71', 'vat' => '0.00', 'gross' => '21.71'],
        ], $converted['groups']);
        $this->assertSame('499.33', $converted['total_net']);
        $this->assertSame('99.87', $converted['total_vat']);
        $this->assertSame('599.20', $converted['total_gross']);
        $this->assertSame($prepared->itemAttributes, $result->itemAttributes);
        $this->assertSame('12.00', $result->invoiceAttributes['paid_amount']);
        $this->assertSame('111.00', $result->invoiceAttributes['amount_due']);
    }

    public function test_uses_half_up_and_full_rate_without_float(): void
    {
        $this->currency('JPY', 'A');
        $prepared = $this->prepared('JPY', summary: [
            ['vat_rate' => '0.00', 'vat_code' => null, 'net' => '1.00', 'vat' => '0.00', 'gross' => '1.00'],
        ]);

        $result = app(InvoiceCurrencyConversionService::class)->apply(
            $prepared,
            $this->context('JPY', 'A'),
            $this->rate('JPY', 'A', '0.005000'),
        );

        $group = $result->invoiceAttributes['tax_metadata_snapshot']['converted_tax_summary']['groups'][0];
        $this->assertSame('0.01', $group['net']);
        $this->assertSame('0.00', $group['vat']);
        $this->assertSame('0.01', $group['gross']);
        $this->assertSnapshotContainsNoFloat($result->invoiceAttributes['tax_metadata_snapshot']);
    }

    public function test_supports_large_values_and_many_rate_decimal_places(): void
    {
        $this->currency('IDR', 'B');
        $prepared = $this->prepared('IDR', summary: [
            ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '9999999999.99', 'vat' => '2300000000.00', 'gross' => '12299999999.99'],
        ]);

        $result = app(InvoiceCurrencyConversionService::class)->apply(
            $prepared,
            $this->context('IDR', 'B'),
            $this->rate('IDR', 'B', '0.000236'),
        );
        $summary = $result->invoiceAttributes['tax_metadata_snapshot']['converted_tax_summary'];

        $this->assertSame('2360000.00', $summary['total_net']);
        $this->assertSame('542800.00', $summary['total_vat']);
        $this->assertSame('2902800.00', $summary['total_gross']);
    }

    public function test_unknown_currency_and_missing_table_are_controlled_errors(): void
    {
        $service = app(InvoiceCurrencyConversionService::class);

        $this->expectCode('invoice_exchange_rate_currency_unknown', fn () => $service->contextFor($this->prepared('XYZ')));
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => null]);
        $this->expectCode('invoice_exchange_rate_table_missing', fn () => $service->contextFor($this->prepared('EUR')));
    }

    public function test_rate_from_other_context_is_rejected(): void
    {
        $this->currency('EUR', 'A');
        $this->expectCode('invoice_exchange_rate_context_changed', function (): void {
            app(InvoiceCurrencyConversionService::class)->apply(
                $this->prepared('EUR'),
                $this->context('EUR', 'A'),
                $this->rate('USD', 'A', '4.00'),
            );
        });
    }

    public function test_prepared_document_from_other_context_is_rejected(): void
    {
        $this->currency('EUR', 'A');

        $this->expectCode('invoice_exchange_rate_context_changed', function (): void {
            app(InvoiceCurrencyConversionService::class)->apply(
                $this->prepared('EUR', issueDate: '2026-07-28', saleDate: '2026-07-21'),
                $this->context('EUR', 'A'),
                $this->rate('EUR', 'A', '4.00'),
            );
        });
    }

    private function context(string $currency, string $table): InvoiceCurrencyConversionContext
    {
        return new InvoiceCurrencyConversionContext(
            currency: $currency,
            issueDate: '2026-07-28',
            saleDate: '2026-07-20',
            referenceDate: '2026-07-20',
            rateRule: 'vat_art_31a_standard_v1',
            nbpTable: $table,
        );
    }

    private function currency(string $code, string $table): void
    {
        Currency::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $code, 'nbp_table' => $table],
        );
    }

    private function rate(string $currency, string $table, string $rate): NbpExchangeRate
    {
        return new NbpExchangeRate(
            source: 'NBP',
            currencyCode: $currency,
            tableType: $table,
            tableNumber: "137/{$table}/NBP/2026",
            effectiveDate: '2026-07-17',
            referenceDate: '2026-07-20',
            rate: $rate,
        );
    }

    /** @param array<int, array<string, ?string>>|null $summary */
    private function prepared(
        string $currency,
        string $issueDate = '2026-07-28',
        string $saleDate = '2026-07-20',
        ?array $summary = null,
    ): PreparedInvoiceDocument {
        return new PreparedInvoiceDocument(
            invoiceAttributes: [
                'currency' => $currency,
                'issue_date' => $issueDate,
                'sale_date' => $saleDate,
                'tax_summary_snapshot' => $summary ?? [
                    ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '100.00', 'vat' => '23.00', 'gross' => '123.00'],
                ],
                'tax_metadata_snapshot' => [],
                'paid_amount' => '12.00',
                'amount_due' => '111.00',
            ],
            itemAttributes: [['name' => 'Produkt', 'total_gross' => '123.00']],
            hashPayload: ['currency' => $currency],
        );
    }

    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Nie zgłoszono błędu {$code}.");
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }

    private function assertSnapshotContainsNoFloat(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertSnapshotContainsNoFloat($item);
            }

            return;
        }

        $this->assertFalse(is_float($value));
    }
}
