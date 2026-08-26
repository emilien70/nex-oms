<?php

namespace Modules\Ksef\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefInvoiceAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly string $eventId;

    public function __construct(public readonly KsefInvoiceSubmission $submission)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function name(): string
    {
        return 'ksef.invoice_accepted';
    }

    public function payload(): array
    {
        $invoice = $this->submission->invoice;

        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'order_id' => $invoice?->order_id,
            'invoice_id' => $invoice?->getKey(),
            'invoice_number' => $invoice?->number,
            'submission_id' => $this->submission->getKey(),
            'environment' => $this->submission->environment->value,
            'ksef_number' => $this->submission->ksef_number,
            'acquisition_date' => $this->submission->acquisition_date?->toISOString(),
        ];
    }
}
