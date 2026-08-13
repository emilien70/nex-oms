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
     *     finalizedCorrections: Collection<int, Invoice>,
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
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Invoice $invoice): string => $invoice->document_type->value);
        $corrections = $documents->get(InvoiceDocumentType::Correction->value, collect());
        $issuedInvoice = $documents->get(InvoiceDocumentType::Invoice->value, collect())->first();
        $issuedProforma = $documents->get(InvoiceDocumentType::Proforma->value, collect())->first();

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
            'issuedInvoice' => $issuedInvoice,
            'issuedProforma' => $issuedProforma,
            'issuedCorrection' => $corrections->first(
                static fn (Invoice $correction): bool => ! $correction->isFinalized(),
            ),
            'finalizedCorrections' => $corrections
                ->filter(static fn (Invoice $correction): bool => $correction->isFinalized())
                ->sortByDesc(fn (Invoice $correction): string => sprintf(
                    '%020d:%020d',
                    $correction->issued_at?->getTimestamp() ?? 0,
                    $correction->getKey(),
                ))
                ->values(),
            'proformaLocked' => (bool) $issuedProforma?->isProformaSuperseded(),
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
