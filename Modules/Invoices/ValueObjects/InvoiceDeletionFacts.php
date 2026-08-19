<?php

namespace Modules\Invoices\ValueObjects;

final readonly class InvoiceDeletionFacts
{
    public function __construct(
        public bool $seriesExists,
        public bool $orderExists,
        public bool $hasCorrection,
        public bool $hasOtherCorrection,
        public bool $hasBlockingKsefSubmission,
    ) {}
}
