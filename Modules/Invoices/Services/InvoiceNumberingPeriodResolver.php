<?php

namespace Modules\Invoices\Services;

use Carbon\CarbonInterface;
use DomainException;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\InvoiceSeries;

class InvoiceNumberingPeriodResolver
{
    public function resolve(InvoiceSeries $series, CarbonInterface $numberingDate): string
    {
        $resetPeriod = InvoiceSeriesResetPeriod::tryFrom((string) $series->getRawOriginal('reset_period'));

        if ($resetPeriod === null) {
            throw new DomainException('Nieznany sposób resetowania numeracji.');
        }

        return match ($resetPeriod) {
            InvoiceSeriesResetPeriod::Monthly => $numberingDate->format('Y-m'),
            InvoiceSeriesResetPeriod::Yearly => (string) $this->fiscalPeriodStartYear($series, $numberingDate),
            InvoiceSeriesResetPeriod::None => 'none',
        };
    }

    private function fiscalPeriodStartYear(InvoiceSeries $series, CarbonInterface $numberingDate): int
    {
        $startMonth = $series->fiscal_year_start_month;

        if ($startMonth < 1 || $startMonth > 12) {
            throw new DomainException('Początek roku fiskalnego jest nieprawidłowy.');
        }

        return $numberingDate->month >= $startMonth
            ? $numberingDate->year
            : $numberingDate->year - 1;
    }
}
