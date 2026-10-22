<?php

namespace Modules\Integrations\Allegro\Listeners;

use App\Events\OrderStatusChanged;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportService;
use Modules\Invoices\Events\CorrectionFinalized;
use Modules\Invoices\Events\InvoiceIssued;
use Modules\Ksef\Events\KsefInvoiceAccepted;

class DispatchAllegroInvoiceExports
{
    public function __construct(private readonly AllegroInvoiceExportService $exports) {}

    public function handle(InvoiceIssued|CorrectionFinalized|OrderStatusChanged|KsefInvoiceAccepted $event): void
    {
        if ($event instanceof InvoiceIssued || $event instanceof CorrectionFinalized) {
            $this->exports->enqueue($event->invoice->id);
        } elseif ($event instanceof OrderStatusChanged) {
            $this->exports->statusChanged($event->order->id);
        } elseif ($event->submission->invoice?->order_id) {
            $this->exports->wakeWaiting($event->submission->invoice->order_id);
        }
    }
}
