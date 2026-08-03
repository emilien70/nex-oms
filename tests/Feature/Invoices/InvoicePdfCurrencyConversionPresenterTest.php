<?php

namespace Tests\Feature\Invoices;

use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfCurrencyConversionPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoicePdfCurrencyConversionPresenterTest extends TestCase
{
    public function test_pln_invoice_and_foreign_legacy_invoice_have_no_conversion_presentation(): void
    {
        $presenter = app(InvoicePdfCurrencyConversionPresenter::class);

        $this->assertNull($presenter->present($this->invoice('PLN', [])));
        $this->assertNull($presenter->present($this->invoice('EUR', [])));
    }

    public function test_proforma_never_has_conversion_presentation(): void
    {
        $invoice = $this->invoice('EUR', $this->metadata());
        $invoice->document_type = InvoiceDocumentType::Proforma;

        $this->assertNull(app(InvoicePdfCurrencyConversionPresenter::class)->present($invoice));
    }

    public function test_presents_exact_rate_totals_and_pairs_groups_by_identity_not_position(): void
    {
        $source = [
            ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '100.00', 'vat' => '23.00', 'gross' => '123.00'],
            ['vat_rate' => '0.00', 'vat_code' => null, 'net' => '50.00', 'vat' => '0.00', 'gross' => '50.00'],
            ['vat_rate' => null, 'vat_code' => 'zw', 'net' => '10.00', 'vat' => '0.00', 'gross' => '10.00'],
        ];
        $groups = [
            ['vat_rate' => null, 'vat_code' => 'ZW', 'net' => '43.42', 'vat' => '0.00', 'gross' => '43.42'],
            ['vat_rate' => '0.00', 'vat_code' => null, 'net' => '217.10', 'vat' => '0.00', 'gross' => '217.10'],
            ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '434.20', 'vat' => '99.87', 'gross' => '534.07'],
        ];
        $metadata = $this->metadata(groups: $groups, totals: [
            'net' => '694.72', 'vat' => '99.87', 'gross' => '794.59',
        ]);

        $result = app(InvoicePdfCurrencyConversionPresenter::class)->present(
            $this->invoice('EUR', $metadata, $source),
        );

        $this->assertSame('EUR', $result['source_currency']);
        $this->assertSame('PLN', $result['target_currency']);
        $this->assertSame('4.3420', $result['rate']);
        $this->assertSame('1 EUR = 4.3420 PLN', $result['rate_text']);
        $this->assertSame('2026-07-17', $result['effective_date']);
        $this->assertSame('137/A/NBP/2026', $result['table_number']);
        $this->assertSame(['net' => '694.72', 'vat' => '99.87', 'gross' => '794.59'], $result['totals']);
        $this->assertSame(['23%', '0%', 'ZW'], array_column(array_column($result['tax_row_pairs'], 'source'), 'vat'));
        $this->assertSame(['434.20', '217.10', '43.42'], array_column(array_column($result['tax_row_pairs'], 'converted'), 'net'));
        $this->assertSame(['23%', '0%', 'ZW'], array_column(array_column($result['tax_row_pairs'], 'converted'), 'vat'));
    }

    #[DataProvider('exactRates')]
    public function test_preserves_exact_rate_text(string $rate): void
    {
        $result = app(InvoicePdfCurrencyConversionPresenter::class)->present(
            $this->invoice('EUR', $this->metadata(rate: $rate)),
        );

        $this->assertSame($rate, $result['rate']);
        $this->assertSame("1 EUR = {$rate} PLN", $result['rate_text']);
    }

    public static function exactRates(): array
    {
        return [['4.3128'], ['4.3420']];
    }

    #[DataProvider('invalidMetadata')]
    public function test_rejects_invalid_nonempty_snapshot(callable $mutate): void
    {
        $metadata = $this->metadata();
        $source = $this->sourceGroups();
        [$metadata, $source] = $mutate($metadata, $source);

        $this->expectException(InvoiceDomainException::class);
        $this->expectExceptionMessage('zapisane dane przeliczenia walutowego są niekompletne');

        app(InvoicePdfCurrencyConversionPresenter::class)->present(
            $this->invoice('EUR', $metadata, $source),
        );
    }

    public static function invalidMetadata(): array
    {
        return [
            'missing conversion section' => [fn (array $m, array $s) => [array_diff_key($m, ['currency_conversion' => true]), $s]],
            'missing summary section' => [fn (array $m, array $s) => [array_diff_key($m, ['converted_tax_summary' => true]), $s]],
            'wrong version' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'version', 2), $s]],
            'wrong source' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'source', 'ECB'), $s]],
            'wrong source currency' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'source_currency', 'USD'), $s]],
            'wrong target currency' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'target_currency', 'EUR'), $s]],
            'wrong table type' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'table_type', 'C'), $s]],
            'empty table number' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'table_number', ' '), $s]],
            'invalid effective date' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'effective_date', '2026-02-30'), $s]],
            'effective date equal reference' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'effective_date', '2026-07-20'), $s]],
            'zero rate' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'rate', '0.0000'), $s]],
            'negative rate' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'rate', '-4.20'), $s]],
            'comma rate' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'rate', '4,20'), $s]],
            'scientific rate' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'rate', '4.2E0'), $s]],
            'wrong rounding' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'rounding_mode', 'half_even'), $s]],
            'wrong scale' => [fn (array $m, array $s) => [self::set($m, 'currency_conversion', 'result_scale', 4), $s]],
            'wrong converted currency' => [fn (array $m, array $s) => [self::set($m, 'converted_tax_summary', 'currency', 'EUR'), $s]],
            'money without two decimals' => [fn (array $m, array $s) => [self::set($m, 'converted_tax_summary', 'total_net', '434.2'), $s]],
            'group gross mismatch' => [fn (array $m, array $s) => [self::setGroup($m, 0, 'gross', '534.08'), $s]],
            'totals gross mismatch' => [fn (array $m, array $s) => [self::set($m, 'converted_tax_summary', 'total_gross', '534.08'), $s]],
            'missing converted group' => [fn (array $m, array $s) => [self::setConvertedGroups($m, []), $s]],
            'additional converted group' => [function (array $m, array $s) {
                $groups = $m['converted_tax_summary']['groups'];
                $groups[] = ['vat_rate' => '0.00', 'vat_code' => null, 'net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];

                return [self::setConvertedGroups($m, $groups), $s];
            }],
            'duplicate source identity' => [function (array $m, array $s) {
                $s[] = $s[0];

                return [$m, $s];
            }],
            'duplicate converted identity' => [function (array $m, array $s) {
                $groups = $m['converted_tax_summary']['groups'];
                $groups[] = $groups[0];

                return [self::setConvertedGroups($m, $groups), $s];
            }],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function invoice(string $currency, array $metadata, ?array $groups = null): Invoice
    {
        return new Invoice([
            'document_type' => InvoiceDocumentType::Invoice,
            'currency' => $currency,
            'tax_summary_snapshot' => $groups ?? $this->sourceGroups(),
            'tax_metadata_snapshot' => $metadata,
        ]);
    }

    /** @return array<int, array<string, ?string>> */
    private function sourceGroups(): array
    {
        return [[
            'vat_rate' => '23.00',
            'vat_code' => null,
            'net' => '100.00',
            'vat' => '23.00',
            'gross' => '123.00',
        ]];
    }

    /** @param array<int, array<string, ?string>>|null $groups
     * @param  array<string, string>|null  $totals
     * @return array<string, mixed>
     */
    private function metadata(string $rate = '4.3420', ?array $groups = null, ?array $totals = null): array
    {
        return [
            'currency_conversion' => [
                'version' => 1,
                'source' => 'NBP',
                'source_currency' => 'EUR',
                'target_currency' => 'PLN',
                'table_type' => 'A',
                'table_number' => '137/A/NBP/2026',
                'effective_date' => '2026-07-17',
                'reference_date' => '2026-07-20',
                'rate' => $rate,
                'rate_rule' => 'vat_art_31a_standard_v1',
                'rounding_mode' => 'half_up',
                'result_scale' => 2,
            ],
            'converted_tax_summary' => [
                'currency' => 'PLN',
                'groups' => $groups ?? [[
                    'vat_rate' => '23.00',
                    'vat_code' => null,
                    'net' => '434.20',
                    'vat' => '99.87',
                    'gross' => '534.07',
                ]],
                'total_net' => $totals['net'] ?? '434.20',
                'total_vat' => $totals['vat'] ?? '99.87',
                'total_gross' => $totals['gross'] ?? '534.07',
            ],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private static function set(array $metadata, string $section, string $key, mixed $value): array
    {
        $metadata[$section][$key] = $value;

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private static function setGroup(array $metadata, int $index, string $key, mixed $value): array
    {
        $metadata['converted_tax_summary']['groups'][$index][$key] = $value;

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private static function setConvertedGroups(array $metadata, array $groups): array
    {
        $metadata['converted_tax_summary']['groups'] = $groups;

        return $metadata;
    }
}
