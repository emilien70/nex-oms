<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Services\InvoiceAmountInWordsFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceAmountInWordsFormatterTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_formats_polish_amounts_without_float(string $amount, string $expected): void
    {
        $this->assertSame($expected, (new InvoiceAmountInWordsFormatter)->format($amount, 'PLN'));
    }

    public static function amounts(): array
    {
        return [
            ['1.00', 'Jeden PLN 00/100 PLN'],
            ['2.05', 'Dwa PLN 05/100 PLN'],
            ['5.12', 'Pięć PLN 12/100 PLN'],
            ['12.00', 'Dwanaście PLN 00/100 PLN'],
            ['21.00', 'Dwadzieścia jeden PLN 00/100 PLN'],
            ['100.00', 'Sto PLN 00/100 PLN'],
            ['169.00', 'Sto sześćdziesiąt dziewięć PLN 00/100 PLN'],
            ['1000.00', 'Jeden tysiąc PLN 00/100 PLN'],
            ['1409.00', 'Jeden tysiąc czterysta dziewięć PLN 00/100 PLN'],
            ['-469.00', '- Czterysta sześćdziesiąt dziewięć PLN 00/100 PLN'],
            ['1000002.99', 'Jeden milion dwa PLN 99/100 PLN'],
        ];
    }

    public function test_other_currency_uses_deterministic_currency_code(): void
    {
        $this->assertSame(
            'Dziesięć EUR 50/100 EUR',
            (new InvoiceAmountInWordsFormatter)->format('10.50', 'eur'),
        );
    }
}
