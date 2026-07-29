<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Facades\Log;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class InvoicePdfService
{
    public function __construct(
        private readonly InvoicePdfRenderer $renderer,
        private readonly InvoicePdfStorage $storage,
    ) {}

    public function contents(Invoice $invoice): string
    {
        try {
            return $this->storage->getOrCreate(
                $invoice,
                fn (): string => $this->renderer->render($invoice),
            );
        } catch (InvoiceDomainException $exception) {
            Log::warning('Nie udało się przygotować PDF dokumentu sprzedaży.', [
                'invoice_id' => $invoice->getKey(),
                'error_code' => $exception->errorCode(),
                'reason' => $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
