<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
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

    private function calculator(): CorrectionTotalsCalculator
    {
        $decimal = new InvoiceDecimalCalculator;

        return new CorrectionTotalsCalculator($decimal, new InvoiceTotalsCalculator($decimal));
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
}
