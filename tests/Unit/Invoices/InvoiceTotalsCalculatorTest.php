<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Invoices\Services\InvoiceTotalsCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceTotalsCalculatorTest extends TestCase
{
    public function test_code_groups_are_canonicalized_merged_and_override_stale_rates(): void
    {
        $totals = $this->calculator()->calculateEditedDocument([
            $this->item('10.00', '10.00', ' zw ', '23.00'),
            $this->item('20.00', '20.00', 'ZW'),
            $this->item('5.00', '5.00', ' np '),
            $this->item('7.00', '7.00', 'NP'),
        ], '0.00');

        $this->assertSame([
            $this->group('NP', '12.00'),
            $this->group('ZW', '30.00'),
        ], $totals['tax_summary_snapshot']);
    }

    public function test_tax_group_order_does_not_depend_on_item_order(): void
    {
        $items = [
            $this->item('12.30', '10.00', null, '23'),
            $this->item('10.80', '10.00', null, '8'),
            $this->item('7.00', '7.00', 'ZW'),
            $this->item('5.00', '5.00', 'NP'),
        ];

        $first = $this->calculator()->calculateEditedDocument($items, '0.00');
        $second = $this->calculator()->calculateEditedDocument(array_reverse($items), '0.00');

        $this->assertSame($first['tax_summary_snapshot'], $second['tax_summary_snapshot']);
        $this->assertSame(
            ['NP', 'ZW', '23.00', '8.00'],
            array_map(
                static fn (array $group): string => $group['vat_code'] ?? $group['vat_rate'],
                $first['tax_summary_snapshot'],
            ),
        );
    }

    private function calculator(): InvoiceTotalsCalculator
    {
        $decimal = new InvoiceDecimalCalculator;

        return new InvoiceTotalsCalculator($decimal, new InvoiceTaxIdentityNormalizer($decimal));
    }

    /** @return array<string, mixed> */
    private function item(string $gross, string $net, ?string $vatCode, ?string $vatRate = null): array
    {
        return [
            'total_net' => $net,
            'total_vat' => $this->calculatorDecimal()->subtract($gross, $net),
            'total_gross' => $gross,
            'vat_rate' => $vatRate,
            'vat_code' => $vatCode,
        ];
    }

    /** @return array<string, mixed> */
    private function group(string $vatCode, string $gross): array
    {
        return [
            'vat_rate' => null,
            'vat_code' => $vatCode,
            'net' => $gross,
            'vat' => '0.00',
            'gross' => $gross,
        ];
    }

    private function calculatorDecimal(): InvoiceDecimalCalculator
    {
        return new InvoiceDecimalCalculator;
    }
}
