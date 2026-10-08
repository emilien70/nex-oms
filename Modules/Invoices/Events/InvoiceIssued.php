<?php

namespace Modules\Invoices\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Invoices\Models\Invoice;

class InvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $source,
    ) {
        $this->eventId = (string) Str::uuid();
    }

    public function name(): string
    {
        return 'invoice.issued';
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'order_id' => $this->invoice->order_id,
            'invoice_id' => $this->invoice->getKey(),
            'invoice_number' => $this->invoice->number,
            'invoice_series_id' => $this->invoice->invoice_series_id,
            'source' => $this->source,
        ];
    }
}
