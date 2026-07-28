<?php

namespace Modules\Invoices\ValueObjects;

use Modules\Invoices\Enums\ProformaOperationStatus;
use Modules\Invoices\Models\Invoice;

final readonly class ProformaOperationResult
{
    public function __construct(
        public Invoice $invoice,
        public ProformaOperationStatus $status,
    ) {}
}
