<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;

class OrderSalesDocumentActionsView
{
    /**
     * @return array{
     *     issuedInvoice: ?Invoice,
     *     issuedProforma: ?Invoice,
     *     issuedCorrection: ?Invoice,
     *     proformaLocked: bool,
     *     invoiceSeries: Collection<int, InvoiceSeries>,
     *     proformaSeries: Collection<int, InvoiceSeries>
     * }
     */
    public function data(Order $order): array
    {
        $documents = Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('status', InvoiceDocumentStatus::Issued)
            ->whereIn('document_type', [
                InvoiceDocumentType::Invoice,
                InvoiceDocumentType::Proforma,
                InvoiceDocumentType::Correction,
            ])
            ->get()
            ->keyBy(fn (Invoice $invoice): string => $invoice->document_type->value);

        $series = InvoiceSeries::query()
            ->where('is_active', true)
            ->whereIn('document_type', [
                InvoiceDocumentType::Invoice,
                InvoiceDocumentType::Proforma,
            ])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (InvoiceSeries $item): string => $item->document_type->value);

        return [
            'issuedInvoice' => $documents->get(InvoiceDocumentType::Invoice->value),
            'issuedProforma' => $documents->get(InvoiceDocumentType::Proforma->value),
            'issuedCorrection' => $documents->get(InvoiceDocumentType::Correction->value),
            'proformaLocked' => (bool) $documents
                ->get(InvoiceDocumentType::Proforma->value)
                ?->isProformaSuperseded(),
            'invoiceSeries' => $series->get(InvoiceDocumentType::Invoice->value, collect()),
            'proformaSeries' => $series->get(InvoiceDocumentType::Proforma->value, collect()),
        ];
    }

    public function render(Order $order): string
    {
        return view('orders.partials.sales-document-actions', [
            'order' => $order,
            'salesDocumentActions' => $this->data($order),
        ])->render();
    }
}
