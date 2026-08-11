<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceMoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceMoneyFormatterTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_formats_money_without_float(string $amount, string $expected): void
    {
        $formatter = new InvoiceMoneyFormatter(new InvoiceDecimalCalculator);

        $this->assertSame($expected, $formatter->format($amount));
    }

    public static function amounts(): array
    {
        return [
            ['0', '0,00'],
            ['12.5', '12,50'],
            ['1234.56', '1 234,56'],
            ['1000000', '1 000 000,00'],
            ['-108.55', '-108,55'],
            ['-0.00', '0,00'],
            ['90071992547409.93', '90 071 992 547 409,93'],
        ];
    }

    public function test_it_keeps_exact_dot_decimal_for_form_inputs(): void
    {
        $formatter = new InvoiceMoneyFormatter(new InvoiceDecimalCalculator);

        $this->assertSame('1234.50', $formatter->formatForInput('1234.5'));
    }
}
