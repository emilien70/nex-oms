<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Invoices\Services\InvoiceTotalsCalculator;
use PHPUnit\Framework\TestCase;

class CorrectionTotalsCalculatorTest extends TestCase
{
    public function test_it_builds_before_after_and_difference_summaries_for_each_vat_rate(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->snapshot('100.00', '23.00', '123.00', '23.00'),
                $this->snapshot('81.30', '18.70', '100.00', '23.00'),
            ),
            $this->item(
                $this->snapshot('100.00', '8.00', '108.00', '8.00'),
                $this->snapshot('50.00', '4.00', '54.00', '8.00'),
            ),
        ]);

        $this->assertSame(['100.00', '100.00'], array_column($totals['before']['tax_summary_snapshot'], 'net'));
        $this->assertSame(['81.30', '50.00'], array_column($totals['after']['tax_summary_snapshot'], 'net'));
        $this->assertSame([
            $this->taxGroup('-18.70', '-4.30', '-23.00', '23.00'),
            $this->taxGroup('-50.00', '-4.00', '-54.00', '8.00'),
        ], $totals['difference']['tax_summary_snapshot']);
        $this->assertSame('-68.70', $totals['difference']['net']);
        $this->assertSame('-8.30', $totals['difference']['vat']);
        $this->assertSame('-77.00', $totals['difference']['gross']);
    }

    public function test_vat_rate_change_keeps_both_groups_when_aggregate_gross_is_zero(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->snapshot('81.30', '18.70', '100.00', '23.00'),
                $this->snapshot('92.59', '7.41', '100.00', '8.00'),
            ),
        ]);

        $this->assertSame('11.29', $totals['difference']['net']);
        $this->assertSame('-11.29', $totals['difference']['vat']);
        $this->assertSame('0.00', $totals['difference']['gross']);
        $this->assertSame([
            $this->taxGroup('-81.30', '-18.70', '-100.00', '23.00'),
            $this->taxGroup('92.59', '7.41', '100.00', '8.00'),
        ], $totals['difference']['tax_summary_snapshot']);
    }

    public function test_unchanged_items_produce_an_empty_difference_summary(): void
    {
        $snapshot = $this->snapshot('100.00', '23.00', '123.00', '23.00');
        $totals = $this->calculator()->calculate([$this->item($snapshot, $snapshot)]);

        $this->assertSame('0.00', $totals['difference']['net']);
        $this->assertSame('0.00', $totals['difference']['vat']);
        $this->assertSame('0.00', $totals['difference']['gross']);
        $this->assertSame([], $totals['difference']['tax_summary_snapshot']);
        $this->assertFalse($this->calculator()->isMonetary($totals['difference']));
    }

    public function test_zw_amount_reduction_uses_zero_vat_code_arithmetic(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->codeSnapshot('100.00', 'ZW'),
                $this->codeSnapshot('75.00', ' zw ', '23.00'),
            ),
        ]);

        $this->assertSame([
            $this->codeGroup('-25.00', '0.00', '-25.00', 'ZW'),
        ], $totals['difference']['tax_summary_snapshot']);
        $this->assertSame('-25.00', $totals['difference']['net']);
        $this->assertSame('0.00', $totals['difference']['vat']);
        $this->assertSame('-25.00', $totals['difference']['gross']);
    }

    public function test_np_amount_reduction_uses_zero_vat_code_arithmetic(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->codeSnapshot('100.00', 'NP'),
                $this->codeSnapshot('60.00', 'np'),
            ),
        ]);

        $this->assertSame([
            $this->codeGroup('-40.00', '0.00', '-40.00', 'NP'),
        ], $totals['difference']['tax_summary_snapshot']);
    }

    public function test_rate_to_code_transition_keeps_both_groups_when_gross_is_unchanged(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->snapshot('100.00', '23.00', '123.00', '23.00'),
                $this->codeSnapshot('123.00', 'ZW'),
            ),
        ]);

        $this->assertSame('23.00', $totals['difference']['net']);
        $this->assertSame('-23.00', $totals['difference']['vat']);
        $this->assertSame('0.00', $totals['difference']['gross']);
        $this->assertSame([
            $this->codeGroup('123.00', '0.00', '123.00', 'ZW'),
            $this->taxGroup('-100.00', '-23.00', '-123.00', '23.00'),
        ], $totals['difference']['tax_summary_snapshot']);
        $this->assertTrue($this->calculator()->isMonetary($totals['difference']));
    }

    public function test_code_to_rate_transition_keeps_both_groups_when_gross_is_unchanged(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->codeSnapshot('123.00', 'ZW'),
                $this->snapshot('100.00', '23.00', '123.00', '23.00'),
            ),
        ]);

        $this->assertSame([
            $this->codeGroup('-123.00', '0.00', '-123.00', 'ZW'),
            $this->taxGroup('100.00', '23.00', '123.00', '23.00'),
        ], $totals['difference']['tax_summary_snapshot']);
    }

    public function test_code_to_code_transition_is_monetary_despite_zero_aggregate_totals(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->codeSnapshot('100.00', 'ZW'),
                $this->codeSnapshot('100.00', 'NP'),
            ),
        ]);

        $this->assertSame('0.00', $totals['difference']['net']);
        $this->assertSame('0.00', $totals['difference']['vat']);
        $this->assertSame('0.00', $totals['difference']['gross']);
        $this->assertSame([
            $this->codeGroup('100.00', '0.00', '100.00', 'NP'),
            $this->codeGroup('-100.00', '0.00', '-100.00', 'ZW'),
        ], $totals['difference']['tax_summary_snapshot']);
        $this->assertTrue($this->calculator()->isMonetary($totals['difference']));
    }

    public function test_np_to_zw_transition_keeps_both_nonzero_groups(): void
    {
        $totals = $this->calculator()->calculate([
            $this->item(
                $this->codeSnapshot('100.00', 'NP'),
                $this->codeSnapshot('100.00', ' zw '),
            ),
        ]);

        $this->assertSame([
            $this->codeGroup('-100.00', '0.00', '-100.00', 'NP'),
            $this->codeGroup('100.00', '0.00', '100.00', 'ZW'),
        ], $totals['difference']['tax_summary_snapshot']);
        $this->assertTrue($this->calculator()->isMonetary($totals['difference']));
    }

    public function test_multi_group_results_are_canonical_and_deterministic(): void
    {
        $items = [
            $this->item($this->codeSnapshot('10.00', ' zw '), $this->codeSnapshot('8.00', 'ZW')),
            $this->item($this->snapshot('10.00', '2.30', '12.30', '23'), $this->snapshot('8.13', '1.87', '10.00', '23.0')),
            $this->item($this->codeSnapshot('15.00', 'np'), $this->codeSnapshot('12.00', 'NP')),
            $this->item($this->snapshot('10.00', '0.80', '10.80', '8'), $this->snapshot('5.00', '0.40', '5.40', '8.00')),
        ];

        $first = $this->calculator()->calculate($items);
        $second = $this->calculator()->calculate(array_reverse($items));

        $this->assertSame($first['before']['tax_summary_snapshot'], $second['before']['tax_summary_snapshot']);
        $this->assertSame($first['after']['tax_summary_snapshot'], $second['after']['tax_summary_snapshot']);
        $this->assertSame($first['difference']['tax_summary_snapshot'], $second['difference']['tax_summary_snapshot']);
        $this->assertSame(
            ['NP', 'ZW', '23.00', '8.00'],
            array_map(
                static fn (array $group): string => $group['vat_code'] ?? $group['vat_rate'],
                $first['difference']['tax_summary_snapshot'],
            ),
        );
    }

    private function calculator(): CorrectionTotalsCalculator
    {
        $decimal = new InvoiceDecimalCalculator;
        $financial = new InvoiceFinancialValueValidator;
        $taxIdentity = new InvoiceTaxIdentityNormalizer($financial);

        return new CorrectionTotalsCalculator(
            $decimal,
            new InvoiceTotalsCalculator($decimal, $taxIdentity, $financial),
            $taxIdentity,
            $financial,
        );
    }

    /** @return array<string, mixed> */
    private function item(array $before, array $after): array
    {
        return [
            'correction_before_snapshot' => $before,
            'correction_after_snapshot' => $after,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(string $net, string $vat, string $gross, string $vatRate): array
    {
        return [
            'total_net' => $net,
            'total_vat' => $vat,
            'total_gross' => $gross,
            'vat_rate' => $vatRate,
            'vat_code' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function codeSnapshot(string $gross, string $vatCode, ?string $staleVatRate = null): array
    {
        return [
            'total_net' => $gross,
            'total_vat' => '0.00',
            'total_gross' => $gross,
            'vat_rate' => $staleVatRate,
            'vat_code' => $vatCode,
        ];
    }

    /** @return array<string, mixed> */
    private function taxGroup(string $net, string $vat, string $gross, string $vatRate): array
    {
        return [
            'vat_rate' => $vatRate,
            'vat_code' => null,
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
        ];
    }

    /** @return array<string, mixed> */
    private function codeGroup(string $net, string $vat, string $gross, string $vatCode): array
    {
        return [
            'vat_rate' => null,
            'vat_code' => $vatCode,
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
        ];
    }
}
