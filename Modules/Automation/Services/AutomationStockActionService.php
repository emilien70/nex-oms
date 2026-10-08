<?php

namespace Modules\Automation\Services;

use DomainException;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Invoices\Models\Invoice;
use Modules\Products\Services\InvoiceStockDeductionService;

class AutomationStockActionService
{
    public function __construct(private readonly InvoiceStockDeductionService $deductions) {}

    public function execute(AutomationRun $run, AutomationRunStep $step): array
    {
        if ($run->event_name !== 'invoice.issued') {
            throw new DomainException('Zmniejszenie stanu magazynowego wymaga zdarzenia wystawienia Faktury VAT.');
        }

        $invoiceId = filter_var(
            data_get($run->event_payload, 'invoice_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $invoice = $invoiceId === false ? null : Invoice::query()->find($invoiceId);

        if ($invoice === null || $invoice->order_id !== $run->order_id) {
            throw new DomainException('Faktura wskazana przez automatyczną akcję nie istnieje lub nie należy do zamówienia.');
        }

        return $this->deductions->deduct($invoice, $run, $step);
    }
}
