<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceFinancialValueValidatorTest extends TestCase
{
    private InvoiceFinancialValueValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new InvoiceFinancialValueValidator;
    }

    public function test_scale_two_storage_accepts_maximum_and_rejects_next_unit(): void
    {
        $this->assertSame(
            '9999999999.99',
            $this->validator->assertFits('9999999999.99', InvoiceFinancialLimits::ORDER_MONEY),
        );

        $this->assertDomainCode(
            'invoice_financial_value_out_of_range',
            fn (): string => $this->validator->assertFits('10000000000.00', InvoiceFinancialLimits::ORDER_MONEY),
        );
    }

    public function test_scale_four_storage_accepts_maximum_and_rejects_next_unit(): void
    {
        $this->assertSame(
            '99999999999.9999',
            $this->validator->assertFits('99999999999.9999', InvoiceFinancialLimits::INVOICE_ITEM_UNIT_PRICE),
        );

        $this->assertDomainCode(
            'invoice_financial_value_out_of_range',
            fn (): string => $this->validator->assertFits(
                '100000000000.0000',
                InvoiceFinancialLimits::INVOICE_ITEM_UNIT_PRICE,
            ),
        );
    }

    public function test_signed_storage_accepts_both_limits_and_rejects_underflow(): void
    {
        $this->assertSame(
            '9999999999999.99',
            $this->validator->assertCorrectionDifference('9999999999999.99'),
        );
        $this->assertSame(
            '-9999999999999.99',
            $this->validator->assertCorrectionDifference('-9999999999999.99'),
        );

        $this->assertDomainCode(
            'invoice_financial_value_out_of_range',
            fn (): string => $this->validator->assertCorrectionDifference('-10000000000000.00'),
        );
    }

    public function test_unsigned_storage_rejects_negative_values(): void
    {
        $this->assertDomainCode(
            'invoice_financial_value_out_of_range',
            fn (): string => $this->validator->assertInvoiceDocumentTotal('-0.01'),
        );
    }

    #[DataProvider('internalVatProvider')]
    public function test_internal_vat_accepts_integral_decimal_representations(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->validator->assertVatPercentage($input));
    }

    /** @return array<string, array{string, string}> */
    public static function internalVatProvider(): array
    {
        return [
            'zero' => ['0', '0.00'],
            'integer 23' => ['23', '23.00'],
            'one decimal 23' => ['23.0', '23.00'],
            'canonical 23' => ['23.00', '23.00'],
            'future 24' => ['24', '24.00'],
            'maximum 100' => ['100.00', '100.00'],
        ];
    }

    #[DataProvider('invalidInternalVatProvider')]
    public function test_internal_vat_rejects_fractional_or_out_of_range_values(string $input): void
    {
        $this->assertDomainCode(
            'invoice_vat_rate_invalid',
            fn (): string => $this->validator->assertVatPercentage($input),
        );
    }

    /** @return array<string, array{string}> */
    public static function invalidInternalVatProvider(): array
    {
        return [
            'fraction 23.50' => ['23.50'],
            'fraction 23.01' => ['23.01'],
            'negative' => ['-1'],
            'over maximum' => ['101'],
        ];
    }

    #[DataProvider('validBusinessVatProvider')]
    public function test_business_vat_input_accepts_any_integer_from_zero_to_one_hundred(string $input): void
    {
        $this->assertTrue($this->validator->isBusinessVatInput($input));
    }

    /** @return array<string, array{string}> */
    public static function validBusinessVatProvider(): array
    {
        return [
            'zero' => ['0'],
            'eight' => ['8'],
            'twenty three' => ['23'],
            'future twenty four' => ['24'],
            'one hundred' => ['100'],
        ];
    }

    #[DataProvider('invalidBusinessVatProvider')]
    public function test_business_vat_input_rejects_decimal_syntax_and_out_of_range_values(string $input): void
    {
        $this->assertFalse($this->validator->isBusinessVatInput($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidBusinessVatProvider(): array
    {
        return [
            'negative' => ['-1'],
            'fraction 0.5' => ['0.5'],
            'fraction 8.5' => ['8.5'],
            'comma fraction' => ['8,5'],
            'one decimal zero' => ['23.0'],
            'canonical two decimals' => ['23.00'],
            'comma decimals' => ['23,00'],
            'fraction 24.50' => ['24.50'],
            'maximum with decimals' => ['100.00'],
            'fraction over maximum' => ['100.01'],
            'over maximum' => ['101'],
            'far over maximum' => ['1000'],
        ];
    }

    public function test_audit_value_is_rejected_against_invoice_storage_without_float_conversion(): void
    {
        $this->assertDomainCode(
            'invoice_financial_value_out_of_range',
            fn (): string => $this->validator->assertInvoiceDocumentTotal('90071992547409.93'),
        );
    }

    private function assertDomainCode(string $expectedCode, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Oczekiwano kontrolowanego błędu domenowego.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode());
        }
    }
}
