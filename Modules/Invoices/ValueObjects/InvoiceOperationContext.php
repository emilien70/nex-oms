<?php

namespace Modules\Invoices\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\Invoices\Enums\InvoiceOperationSource;

final readonly class InvoiceOperationContext
{
    /**
     * @param  array<string, mixed>|null  $actorSnapshot
     */
    public function __construct(
        public InvoiceOperationSource $source,
        public ?array $actorSnapshot,
        public CarbonImmutable $occurredAt,
    ) {}
}
