<?php

namespace Modules\Invoices\Services;

use DomainException;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\InvoiceSeries;

class InvoiceNumberingConfigurationValidator
{
    public function validateSeries(InvoiceSeries $series): void
    {
        $this->validate(
            (string) $series->number_format,
            $series->reset_period,
            (int) $series->fiscal_year_start_month,
        );
    }

    public function validate(
        string $numberFormat,
        InvoiceSeriesResetPeriod|string $resetPeriod,
        int $fiscalYearStartMonth,
    ): void {
        $resolvedResetPeriod = is_string($resetPeriod)
            ? InvoiceSeriesResetPeriod::tryFrom($resetPeriod)
            : $resetPeriod;

        if ($resolvedResetPeriod === null) {
            throw new DomainException('Nieznany sposób resetowania numeracji.');
        }

        if (preg_match('/%N+/', $numberFormat) !== 1) {
            throw new DomainException('Format numeracji musi zawierać token %N.');
        }

        if ($fiscalYearStartMonth < 1 || $fiscalYearStartMonth > 12) {
            throw new DomainException('Początek roku fiskalnego musi być miesiącem od 1 do 12.');
        }

        $hasMonth = str_contains($numberFormat, '%M');
        $hasYear = str_contains($numberFormat, '%Y') || str_contains($numberFormat, '%y');

        if ($resolvedResetPeriod === InvoiceSeriesResetPeriod::Monthly && (! $hasMonth || ! $hasYear)) {
            throw new DomainException(
                'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.'
            );
        }

        if ($resolvedResetPeriod !== InvoiceSeriesResetPeriod::Yearly) {
            return;
        }

        if ($fiscalYearStartMonth === 1 && ! $hasYear) {
            throw new DomainException(
                'Przy rocznym resetowaniu numeracji format musi zawierać token roku %Y lub %y.'
            );
        }

        if ($fiscalYearStartMonth !== 1 && (! $hasMonth || ! $hasYear)) {
            throw new DomainException(
                'Przy rocznym resetowaniu z początkiem roku innym niż styczeń format musi zawierać token miesiąca %M oraz token roku %Y lub %y.'
            );
        }
    }
}
