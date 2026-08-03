<?php

namespace Modules\Invoices\ValueObjects;

final readonly class NbpExchangeRate
{
    public function __construct(
        public string $source,
        public string $currencyCode,
        public string $tableType,
        public string $tableNumber,
        public string $effectiveDate,
        public string $referenceDate,
        public string $rate,
    ) {}
}
