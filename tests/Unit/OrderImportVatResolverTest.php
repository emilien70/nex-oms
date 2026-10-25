<?php

namespace Tests\Unit;

use Modules\Integrations\Services\OrderImportVatResolver;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderImportVatResolverTest extends TestCase
{
    #[DataProvider('sourceRates')]
    public function test_source_distinguishes_missing_zero_special_and_invalid_values(mixed $value, bool $exempt, string $status, ?string $rate): void
    {
        $resolver = new OrderImportVatResolver(new InvoiceFinancialValueValidator);

        $this->assertSame(['status' => $status, 'rate' => $rate], $resolver->source($value, $exempt));
    }

    public static function sourceRates(): array
    {
        return [
            [null, false, 'missing', null], ['', false, 'missing', null],
            ['0', false, 'valid', '0.00'], [0, false, 'valid', '0.00'],
            ['23.00', false, 'valid', '23.00'], ['7.50', false, 'valid', '7.50'],
            [null, true, 'special', null], ['0', true, 'special', null],
            ['zw', false, 'invalid', null], ['NP', false, 'invalid', null],
            ['101', false, 'invalid', null], ['-1', false, 'invalid', null],
            ['23.001', false, 'invalid', null], [false, false, 'invalid', null],
            [[], false, 'invalid', null], [23.0, false, 'invalid', null],
        ];
    }

    public function test_default_is_not_used_to_hide_invalid_or_exempt_source_tax(): void
    {
        $resolver = new OrderImportVatResolver(new InvoiceFinancialValueValidator);
        foreach ([$resolver->source('wrong'), $resolver->source(null, true)] as $source) {
            $result = $resolver->resolve($source, '23.00', true, true, true, '8.00');
            $this->assertNull($result['rate']);
            $this->assertNull($result['source']);
        }
    }

    public function test_empty_catalog_preserves_channel_and_missing_source_uses_only_valid_domestic_default(): void
    {
        $resolver = new OrderImportVatResolver(new InvoiceFinancialValueValidator);
        $this->assertSame('8.00', $resolver->resolve($resolver->source('8'), null, true, true, true, '23')['rate']);
        $missing = $resolver->source(null);
        $this->assertSame('0.00', $resolver->resolve($missing, null, true, true, true, '0')['rate']);
        $this->assertNull($resolver->resolve($missing, null, true, true, false, '23')['rate']);
        $this->assertNull($resolver->resolve($missing, null, true, true, true, 'wrong')['rate']);
    }
}
