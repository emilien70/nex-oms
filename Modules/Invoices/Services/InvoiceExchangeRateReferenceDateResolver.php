<?php

namespace Modules\Invoices\Services;

use DateTimeImmutable;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\ValueObjects\InvoiceExchangeRateReference;

class InvoiceExchangeRateReferenceDateResolver
{
    public const STANDARD_RULE = 'vat_art_31a_standard_v1';

    public const INVOICE_BEFORE_TAX_OBLIGATION_RULE = 'vat_art_31a_invoice_before_tax_obligation_v1';

    public function resolve(mixed $issueDate, mixed $saleDate): InvoiceExchangeRateReference
    {
        $issue = $this->parseDate($issueDate);
        $sale = $this->parseDate($saleDate);

        if ($issue >= $sale) {
            return new InvoiceExchangeRateReference(
                referenceDate: $sale->format('Y-m-d'),
                rateRule: self::STANDARD_RULE,
            );
        }

        return new InvoiceExchangeRateReference(
            referenceDate: $issue->format('Y-m-d'),
            rateRule: self::INVOICE_BEFORE_TAX_OBLIGATION_RULE,
        );
    }

    private function parseDate(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw $this->missingDate();
        }

        $normalized = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $normalized) {
            throw $this->missingDate();
        }

        return $date;
    }

    private function missingDate(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_exchange_rate_reference_date_missing',
            'Nie można ustalić daty właściwej dla kursu NBP.',
        );
    }
}
