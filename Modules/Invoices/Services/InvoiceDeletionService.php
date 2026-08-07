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
                $slot = $this->lockDocumentSlot($managedOrder, $managedInvoice);

                $this->policy->assertDeletable($managedInvoice, $slot, $expectedLockVersion);
                $this->deleteManagedInvoice($managedOrder, $managedInvoice, $slot, $context);

                return $managedOrder;
            }, 3);

            $this->deletePdfSafely($invoice);

            return $order;
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            throw new InvoiceDomainException(
                'invoice_delete_numbering_inconsistent',
                match (true) {
                    $invoice->isProforma() => 'Nie można usunąć Pro formy, ponieważ wykryto niespójność numeracji.',
                    $invoice->isCorrection() => 'Nie można usunąć Korekty, ponieważ wykryto niespójność numeracji.',
                    default => 'Nie można usunąć Faktury, ponieważ wykryto niespójność numeracji.',
                },
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    /**
     * @param  array<int, int>  $expectedLockVersions
     */
    public function deleteMany(
        array $expectedLockVersions,
        InvoiceOperationContext $context,
        InvoiceDocumentType $documentType = InvoiceDocumentType::Invoice,
    ): int {
        if ($expectedLockVersions === []) {
            return 0;
        }

        $invoiceIds = array_values(array_unique(array_map('intval', array_keys($expectedLockVersions))));

        try {
            /** @var array<int, Invoice> $deletedInvoices */
            $deletedInvoices = DB::transaction(function () use ($invoiceIds, $expectedLockVersions, $context, $documentType): array {
                $references = Invoice::query()
                    ->whereIn('id', $invoiceIds)
                    ->get(['id', 'order_id', 'document_type']);

                if ($references->count() !== count($invoiceIds)) {
                    throw new InvoiceDomainException(
                        'invoice_bulk_delete_missing_document',
                        match ($documentType) {
                            InvoiceDocumentType::Invoice => 'Jedna z zaznaczonych Faktur już nie istnieje.',
                            InvoiceDocumentType::Proforma => 'Jedna z zaznaczonych Pro form już nie istnieje.',
                            InvoiceDocumentType::Correction => 'Jedna z zaznaczonych Korekt już nie istnieje.',
                        },
                    );
                }

                if ($references->contains(
                    fn (Invoice $invoice): bool => $invoice->document_type !== $documentType
                )) {
                    throw new InvoiceDomainException(
                        'invoice_bulk_delete_wrong_document_type',
                        match ($documentType) {
                            InvoiceDocumentType::Invoice => 'Zaznaczone dokumenty muszą być Fakturami VAT.',
                            InvoiceDocumentType::Proforma => 'Zaznaczone dokumenty muszą być Pro formami.',
                            InvoiceDocumentType::Correction => 'Zaznaczone dokumenty muszą być Korektami.',
                        },
                    );
                }

                $orderIds = $references
                    ->pluck('order_id')
                    ->filter()
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values();

                $orders = Order::query()
                    ->whereIn('id', $orderIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $invoices = Invoice::query()
                    ->whereIn('id', $invoiceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $slots = OrderDocumentSlot::query()
                    ->whereIn('order_id', $orderIds)
                    ->where('document_type', $documentType)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('order_id');

                foreach ($invoiceIds as $invoiceId) {
                    /** @var Invoice $invoice */
                    $invoice = $invoices->get($invoiceId);
                    $this->policy->assertHasOrderReference($invoice);
                    $this->policy->assertDeletable(
                        $invoice,
                        $slots->get($invoice->order_id),
                        $expectedLockVersions[$invoiceId],
                    );
                }

                $orderedInvoices = $invoices->values()->sort(static function (Invoice $left, Invoice $right): int {
                    return ($left->invoice_series_id <=> $right->invoice_series_id)
                        ?: strcmp((string) $left->numbering_period_key, (string) $right->numbering_period_key)
                        ?: ($right->sequence_number <=> $left->sequence_number)
                        ?: ($left->getKey() <=> $right->getKey());
                });

                $deleted = [];

                foreach ($orderedInvoices as $invoice) {
                    /** @var Order $order */
                    $order = $orders->get($invoice->order_id);
                    $this->deleteManagedInvoice(
                        $order,
                        $invoice,
                        $slots->get($invoice->order_id),
                        $context,
                    );
                    $deleted[] = $invoice;
                }

                return $deleted;
            }, 3);

            foreach ($deletedInvoices as $invoice) {
                $this->deletePdfSafely($invoice);
            }

            return count($deletedInvoices);
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            throw new InvoiceDomainException(
                'invoice_delete_numbering_inconsistent',
                match ($documentType) {
                    InvoiceDocumentType::Invoice => 'Nie można usunąć Faktur, ponieważ wykryto niespójność numeracji.',
                    InvoiceDocumentType::Proforma => 'Nie można usunąć Pro form, ponieważ wykryto niespójność numeracji.',
                    InvoiceDocumentType::Correction => 'Nie można usunąć Korekt, ponieważ wykryto niespójność numeracji.',
                },
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    /** Test seam proving that deletion and counter rollback share one transaction. */
    protected function afterNumberReleased(Invoice $invoice): void {}

    private function deleteManagedInvoice(
        Order $order,
        Invoice $invoice,
        ?OrderDocumentSlot $slot,
        InvoiceOperationContext $context,
    ): void {
        $this->numbering->releaseTailNumberAfterDeletion(
            $invoice,
            $context->actorSnapshot,
        );
        $restoredProforma = $invoice->isInvoice()
            ? $this->restoreSupersededProforma($order, $invoice)
            : null;
        $this->afterNumberReleased($invoice);
        $this->createOrderEvent($order, $invoice, $context);

        if ($restoredProforma !== null) {
            $this->createProformaRestoredOrderEvent(
                $order,
                $restoredProforma,
                $invoice,
                $context,
            );
        }

        $this->releaseDocumentSlot($invoice, $slot);
        $invoice->delete();
    }

    private function lockDocumentSlot(Order $order, Invoice $invoice): ?OrderDocumentSlot
    {
        $slot = OrderDocumentSlot::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', $invoice->document_type)
            ->lockForUpdate()
            ->first();

        if ($slot !== null || ! $invoice->isCorrection() || $invoice->corrected_invoice_id === null) {
            return $slot;
        }

        $corrections = Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Correction)
            ->where('status', InvoiceDocumentStatus::Issued)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($corrections->count() !== 1 || ! $corrections->first()->is($invoice)) {
            return null;
        }

        return OrderDocumentSlot::query()->create([
            'order_id' => $order->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'invoice_id' => $invoice->getKey(),
        ]);
    }

    private function releaseDocumentSlot(Invoice $invoice, ?OrderDocumentSlot $slot): void
    {
        $slot?->delete();
    }

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
        $isProforma = $invoice->isProforma();
        $isCorrection = $invoice->isCorrection();
        $event = $order->events()->make([
            'event_type' => match (true) {
                $isProforma => 'proforma_deleted',
                $isCorrection => 'correction_deleted',
                default => 'invoice_deleted',
            },
            'title' => match (true) {
                $isProforma => 'Usunięto Pro formę',
                $isCorrection => 'Usunięto korektę',
                default => 'Usunięto fakturę',
            },
            'description' => match (true) {
                $isProforma => 'Usunięto Pro formę z zamówienia.',
                $isCorrection => 'Usunięto korektę z zamówienia.',
                default => 'Usunięto fakturę VAT z zamówienia.',
            },
            'payload' => [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->number,
                'invoice_series_id' => $invoice->invoice_series_id,
                'invoice_series_name' => $invoice->series_name_snapshot,
                'sequence_number' => $invoice->sequence_number,
                'numbering_period_key' => $invoice->numbering_period_key,
                'corrected_invoice_id' => $invoice->corrected_invoice_id,
                'previous_correction_id' => $invoice->previous_correction_id,
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
            Log::warning('Nie udało się usunąć cache PDF usuniętego dokumentu sprzedaży.', [
                'invoice_id' => $invoice->getKey(),
                'exception' => $exception,
            ]);
        }
    }
}
