<?php

namespace Modules\Ksef\ValueObjects\Fa3;

final readonly class KsefFa3CorrectionRootInvoice
{
    public function __construct(
        public int $invoiceId,
        public string $number,
        public string $localIssueDate,
    ) {}
}
