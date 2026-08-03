<?php

namespace Modules\Invoices\ValueObjects;

final readonly class InvoiceCurrencyConversionContext
{
    public function __construct(
        public string $currency,
        public string $issueDate,
        public string $saleDate,
        public ?string $referenceDate,
        public ?string $rateRule,
        public ?string $nbpTable,
    ) {}

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->issueDate === $other->issueDate
            && $this->saleDate === $other->saleDate
            && $this->referenceDate === $other->referenceDate
            && $this->rateRule === $other->rateRule
            && $this->nbpTable === $other->nbpTable;
    }

    public function requiresConversion(): bool
    {
        return $this->currency !== 'PLN';
    }
}
