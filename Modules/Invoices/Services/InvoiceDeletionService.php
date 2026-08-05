<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;
use Throwable;

class InvoiceDeletionService
{
    public function __construct(
        private readonly InvoiceDeletionPolicy $policy,
        private readonly InvoiceNumberingService $numbering,
        private readonly InvoicePdfStorage $pdfStorage,
    ) {}

    public function delete(
        Invoice $invoice,
        int $expectedLockVersion,
        InvoiceOperationContext $context,
    ): Order {
        try {
            $this->policy->assertHasOrderReference($invoice);

            $order = DB::transaction(function () use ($invoice, $expectedLockVersion, $context): Order {
                $managedOrder = Order::query()
                    ->lockForUpdate()
                    ->findOrFail($invoice->order_id);
                $managedInvoice = Invoice::query()
                    ->lockForUpdate()
                    ->findOrFail($invoice->getKey());
                $slot = OrderDocumentSlot::query()
                    ->where('order_id', $managedOrder->getKey())
                    ->where('document_type', InvoiceDocumentType::Invoice)
                    ->lockForUpdate()
                    ->first();

                $this->policy->assertDeletable($managedInvoice, $slot, $expectedLockVersion);
                $this->numbering->releaseTailNumberAfterDeletion(
                    $managedInvoice,
                    $context->actorSnapshot,
                );
                $restoredProforma = $this->restoreSupersededProforma($managedOrder, $managedInvoice);
                $this->afterNumberReleased($managedInvoice);
                $this->createOrderEvent($managedOrder, $managedInvoice, $context);

                if ($restoredProforma !== null) {
                    $this->createProformaRestoredOrderEvent(
                        $managedOrder,
                        $restoredProforma,
                        $managedInvoice,
                        $context,
                    );
                }

                $slot?->delete();
                $managedInvoice->delete();

                return $managedOrder;
            }, 3);

            $this->deletePdfSafely($invoice);

            return $order;
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            throw new InvoiceDomainException(
                'invoice_delete_numbering_inconsistent',
                'Nie można usunąć Faktury, ponieważ wykryto niespójność numeracji.',
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    /** Test seam proving that deletion and counter rollback share one transaction. */
    protected function afterNumberReleased(Invoice $invoice): void {}

    private function restoreSupersededProforma(Order $order, Invoice $invoice): ?Invoice
    {
        $linkedDocuments = Invoice::query()
            ->where('superseded_by_invoice_id', $invoice->getKey())
            ->lockForUpdate()
            ->get();

        if ($linkedDocuments->count() > 1) {
            throw new InvoiceDomainException(
                'invoice_delete_inconsistent_document',
                'Nie można usunąć Faktury, ponieważ wykryto niespójne powiązania z Pro formą.',
            );
        }

        /** @var Invoice|null $proforma */
        $proforma = $linkedDocuments->first();

        if ($proforma === null) {
            return null;
        }

        if (
            $proforma->order_id !== $order->getKey()
            || $proforma->document_type !== InvoiceDocumentType::Proforma
            || $proforma->status !== InvoiceDocumentStatus::Issued
            || ! $proforma->isProformaSuperseded()
        ) {
            throw new InvoiceDomainException(
                'invoice_delete_inconsistent_document',
                'Nie można usunąć Faktury, ponieważ wykryto niespójne powiązanie z Pro formą.',
            );
        }

        $proforma->proforma_superseded_at = null;
        $proforma->superseded_by_invoice_id = null;
        $proforma->save();

        return $proforma;
    }

    private function createOrderEvent(
        Order $order,
        Invoice $invoice,
        InvoiceOperationContext $context,
    ): void {
        $event = $order->events()->make([
            'event_type' => 'invoice_deleted',
            'title' => 'Usunięto fakturę',
            'description' => 'Usunięto fakturę VAT z zamówienia.',
            'payload' => [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->number,
                'invoice_series_id' => $invoice->invoice_series_id,
                'invoice_series_name' => $invoice->series_name_snapshot,
                'sequence_number' => $invoice->sequence_number,
                'numbering_period_key' => $invoice->numbering_period_key,
                'total_gross' => $invoice->total_gross,
                'currency' => $invoice->currency,
                'source' => $context->source->value,
                'actor' => $context->actorSnapshot,
            ],
        ]);
        $event->created_at = $context->occurredAt;
        $event->updated_at = $context->occurredAt;
        $event->save();
    }

    private function createProformaRestoredOrderEvent(
        Order $order,
        Invoice $proforma,
        Invoice $deletedInvoice,
        InvoiceOperationContext $context,
    ): void {
        $event = $order->events()->make([
            'event_type' => 'proforma_restored',
            'title' => 'Przywrócono pro formę',
            'description' => 'Przywrócono pro formę po usunięciu faktury VAT.',
            'payload' => [
                'proforma_id' => $proforma->getKey(),
                'proforma_number' => $proforma->number,
                'deleted_invoice_id' => $deletedInvoice->getKey(),
                'deleted_invoice_number' => $deletedInvoice->number,
                'source' => $context->source->value,
                'actor' => $context->actorSnapshot,
            ],
        ]);
        $event->created_at = $context->occurredAt;
        $event->updated_at = $context->occurredAt;
        $event->save();
    }

    private function deletePdfSafely(Invoice $invoice): void
    {
        try {
            $this->pdfStorage->delete($invoice);
        } catch (Throwable $exception) {
            Log::warning('Nie udało się usunąć cache PDF usuniętej Faktury.', [
                'invoice_id' => $invoice->getKey(),
                'exception' => $exception,
            ]);
        }
    }
}
