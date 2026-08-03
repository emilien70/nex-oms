<?php

namespace Modules\Invoices\ValueObjects;

final readonly class InvoiceExchangeRateReference
{
    public function __construct(
        public string $referenceDate,
        public string $rateRule,
    ) {}
}
