<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceExchangeRateReferenceDateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceExchangeRateReferenceDateResolverTest extends TestCase
{
    #[DataProvider('validDates')]
    public function test_resolves_reference_date_and_rule(
        string $issueDate,
        string $saleDate,
        string $expectedDate,
        string $expectedRule,
    ): void {
        $result = (new InvoiceExchangeRateReferenceDateResolver)->resolve($issueDate, $saleDate);

        $this->assertSame($expectedDate, $result->referenceDate);
        $this->assertSame($expectedRule, $result->rateRule);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function validDates(): array
    {
        return [
            'same date' => ['2026-07-20', '2026-07-20', '2026-07-20', InvoiceExchangeRateReferenceDateResolver::STANDARD_RULE],
            'invoice after sale' => ['2026-07-22', '2026-07-20', '2026-07-20', InvoiceExchangeRateReferenceDateResolver::STANDARD_RULE],
            'invoice before sale' => ['2026-07-20', '2026-07-22', '2026-07-20', InvoiceExchangeRateReferenceDateResolver::INVOICE_BEFORE_TAX_OBLIGATION_RULE],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_rejects_missing_or_invalid_dates(mixed $issueDate, mixed $saleDate): void
    {
        try {
            (new InvoiceExchangeRateReferenceDateResolver)->resolve($issueDate, $saleDate);
            $this->fail('Nieprawidłowe daty zostały zaakceptowane.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_exchange_rate_reference_date_missing', $exception->errorCode());
        }
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function invalidDates(): array
    {
        return [
            'missing issue date' => [null, '2026-07-20'],
            'missing sale date' => ['2026-07-20', null],
            'empty issue date' => ['', '2026-07-20'],
            'invalid format' => ['20.07.2026', '2026-07-20'],
            'invalid calendar date' => ['2026-02-30', '2026-07-20'],
        ];
    }
}
