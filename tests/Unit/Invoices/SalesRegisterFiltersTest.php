<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesRegisterFiltersTest extends TestCase
{
    public function test_month_and_manual_range_have_one_normalized_period(): void
    {
        $month = SalesRegisterFilters::forPeriod(['month' => '2024-02', 'series_ids' => ['2', 1, 2]]);
        $this->assertSame('2024-02-01', $month->issueFrom);
        $this->assertSame('2024-02-29', $month->issueTo);
        $this->assertSame([1, 2], $month->seriesIds);
        $manual = SalesRegisterFilters::forPeriod([
            'month' => '2026-01', 'issue_from' => '2026-08-01', 'issue_to' => '2026-09-02',
            'series_ids' => [1], 'currency' => 'eur', 'country' => 'de', 'include_ksef' => false,
        ]);
        $this->assertSame('2026-08-01', $manual->issueFrom);
        $this->assertSame('2026-09-02', $manual->issueTo);
        $this->assertSame('EUR', $manual->currency);
        $this->assertSame('DE', $manual->country);
        $this->assertFalse($manual->includeKsef);
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_period_filters_are_rejected(array $changes): void
    {
        $this->expectException(InvoiceDomainException::class);
        SalesRegisterFilters::forPeriod(array_replace(['month' => '2026-08', 'series_ids' => [1]], $changes));
    }

    public static function invalidPeriods(): array
    {
        return [
            [['month' => '2026-13']], [['month' => '2026-2']], [['month' => null]],
            [['issue_from' => '2026-02-30', 'issue_to' => '2026-03-01']],
            [['issue_from' => '2026-08-01']], [['issue_to' => '2026-08-31']],
            [['issue_from' => '2026-08-02', 'issue_to' => '2026-08-01']],
            [['sale_from' => '2026-08-02', 'sale_to' => '2026-08-01']], [['sale_from' => 'yesterday']],
            [['series_ids' => []]], [['series_ids' => [0]]], [['series_ids' => [-1]]],
            [['series_ids' => [true]]], [['series_ids' => [1.5]]], [['series_ids' => ['1e2']]],
            [['series_ids' => ['9999999999999999999999']]], [['series_ids' => ['x' => 1]]],
            [['tax_id_presence' => 'polish']], [['currency' => '']], [['currency' => ['EUR']]],
            [['country' => 'Polska']], [['include_ksef' => 'false']],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_explicit_selection_never_accepts_empty_or_invalid_ids(array $ids): void
    {
        $this->expectException(InvoiceDomainException::class);
        SalesRegisterFilters::forDocuments($ids);
    }

    public static function invalidIds(): array
    {
        return [[[]], [[null]], [[0]], [[false]], [['abc']], [[1.2]]];
    }

    public function test_explicit_selection_is_independent_of_period_filters(): void
    {
        $filters = SalesRegisterFilters::forDocuments([3, '1', 3], false);
        $this->assertSame('ids', $filters->mode);
        $this->assertSame([1, 3], $filters->documentIds);
        $this->assertSame([], $filters->seriesIds);
        $this->assertNull($filters->issueFrom);
        $this->assertFalse($filters->includeKsef);
    }
}
