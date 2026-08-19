<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\ValueObjects\InvoiceDeletionFacts;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Throwable;

class InvoiceDeletionService
{
    public function __construct(
        private readonly InvoiceDeletionPolicy $policy,
        private readonly CorrectionSourceStateService $sourceState,
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
                if ($managedInvoice->isCorrection() && $managedInvoice->corrected_invoice_id !== null) {
                    $managedInvoice->setRelation(
                        'correctedInvoice',
                        Invoice::query()->lockForUpdate()->find($managedInvoice->corrected_invoice_id),
                    );
                }
                $slot = OrderDocumentSlot::query()
                    ->where('order_id', $managedOrder->getKey())
                    ->where('document_type', $managedInvoice->document_type)
                    ->lockForUpdate()
                    ->first();
                $corrections = $managedInvoice->isCorrection()
                    ? $this->lockCorrectionsByOrder([$managedOrder->getKey()])
                        ->get($managedOrder->getKey(), collect())
                    : collect();
                $slot = $this->resolveDocumentSlotForDeletion(
                    $managedOrder,
                    $managedInvoice,
                    $slot,
                    $corrections,
                );
                $facts = new InvoiceDeletionFacts(
                    seriesExists: $managedInvoice->series()->exists(),
                    orderExists: true,
                    hasCorrection: $managedInvoice->isInvoice() && $managedInvoice->corrections()->exists(),
                    hasOtherCorrection: $managedInvoice->isCorrection()
                        && $this->hasOtherCorrection($managedInvoice, $corrections),
                    hasBlockingKsefSubmission: $managedInvoice->isInvoice()
                        && $managedInvoice->ksefSubmissions()
                            ->whereIn('status', $this->blockingKsefSubmissionStatuses())
                            ->exists(),
                );

                $this->policy->assertDeletable(
                    $managedInvoice,
                    $slot,
                    $expectedLockVersion,
                    $facts,
                );
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
                    ->whereIntegerInRaw('id', $invoiceIds)
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
                    ->whereIntegerInRaw('id', $orderIds->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $invoices = Invoice::query()
                    ->whereIntegerInRaw('id', $invoiceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $rootInvoices = $documentType === InvoiceDocumentType::Correction
                    ? Invoice::query()
                        ->whereIntegerInRaw(
                            'id',
                            $invoices->pluck('corrected_invoice_id')->filter()->unique()->values()->all(),
                        )
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id')
                    : collect();

                if ($documentType === InvoiceDocumentType::Correction) {
                    $invoices->each(function (Invoice $invoice) use ($rootInvoices): void {
                        $invoice->setRelation(
                            'correctedInvoice',
                            $rootInvoices->get($invoice->corrected_invoice_id),
                        );
                    });
                }

                $slots = OrderDocumentSlot::query()
                    ->whereIntegerInRaw('order_id', $orderIds->all())
                    ->where('document_type', $documentType)
                    ->orderBy('order_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('order_id');
                $correctionsByOrder = $documentType === InvoiceDocumentType::Correction
                    ? $this->lockCorrectionsByOrder($orderIds->all())
                    : collect();
                $factsByInvoice = $this->buildDeletionFacts(
                    $invoices,
                    $orders,
                    $correctionsByOrder,
                );
                $linkedProformasByInvoice = $documentType === InvoiceDocumentType::Invoice
                    ? $this->lockSupersededProformasByInvoice($invoiceIds)
                    : collect();

                foreach ($invoiceIds as $invoiceId) {
                    /** @var Invoice $invoice */
                    $invoice = $invoices->get($invoiceId);
                    $this->policy->assertHasOrderReference($invoice);
                    $facts = $factsByInvoice->get($invoiceId);

                    if (! $facts instanceof InvoiceDeletionFacts) {
                        throw new LogicException('Incomplete deletion facts for a bulk document.');
                    }

                    $order = $orders->get($invoice->order_id);

                    if (! $order instanceof Order) {
                        $this->policy->assertDeletable(
                            $invoice,
                            null,
                            $expectedLockVersions[$invoiceId],
                            $facts,
                        );

                        throw new LogicException('Deletion policy accepted a document without an order.');
                    }

                    /** @var Collection<int, Invoice> $corrections */
                    $corrections = $correctionsByOrder->get($invoice->order_id, collect());
                    $slot = $this->resolveDocumentSlotForDeletion(
                        $order,
                        $invoice,
                        $slots->get($invoice->order_id),
                        $corrections,
                    );

                    if ($slot !== null) {
                        $slots->put($invoice->order_id, $slot);
                    }

                    $this->policy->assertDeletable(
                        $invoice,
                        $slot,
                        $expectedLockVersions[$invoiceId],
                        $facts,
                    );

                    if ($invoice->isInvoice()) {
                        $this->validatedSupersededProforma(
                            $order,
                            $linkedProformasByInvoice->get($invoiceId, collect()),
                        );
                    }
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
                        $invoice->isInvoice()
                            ? $linkedProformasByInvoice->get($invoice->getKey(), collect())
                            : null,
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
        ?Collection $linkedProformas = null,
    ): void {
        $this->numbering->releaseTailNumberAfterDeletion(
            $invoice,
            $context->actorSnapshot,
        );
        $restoredProforma = $invoice->isInvoice()
            ? $this->restoreSupersededProforma($order, $invoice, $linkedProformas)
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

    /**
     * @param  Collection<int, Invoice>  $corrections
     */
    private function resolveDocumentSlotForDeletion(
        Order $order,
        Invoice $invoice,
        ?OrderDocumentSlot $slot,
        Collection $corrections,
    ): ?OrderDocumentSlot {
        if (! $invoice->isCorrection()) {
            return $slot;
        }

        $source = $invoice->relationLoaded('correctedInvoice')
            ? $invoice->correctedInvoice
            : null;

        if (! $source instanceof Invoice) {
            return $slot;
        }

        try {
            $chain = $this->sourceState->resolveChain($source, $corrections, $slot);
        } catch (InvoiceDomainException $exception) {
            if (! in_array($exception->errorCode(), [
                'correction_chain_inconsistent',
                'correction_document_slot_inconsistent',
            ], true)) {
                throw $exception;
            }

            throw new InvoiceDomainException(
                'correction_delete_inconsistent_document',
                'Nie można usunąć Korekty, ponieważ jej dane lub powiązania są niespójne.',
                previous: $exception,
            );
        }

        if (! $chain->legacyCurrentWithoutSlot
            || $chain->currentCorrection === null
            || ! $chain->currentCorrection->is($invoice)) {
            return $chain->slot;
        }

        return OrderDocumentSlot::query()->create([
            'order_id' => $order->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'invoice_id' => $invoice->getKey(),
        ]);
    }

    /**
     * @param  array<int, int>  $orderIds
     * @return Collection<int, Collection<int, Invoice>>
     */
    private function lockCorrectionsByOrder(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return Invoice::query()
            ->whereIntegerInRaw('order_id', $orderIds)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->orderBy('order_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('order_id');
    }

    /** @param Collection<int, Invoice> $corrections */
    private function hasOtherCorrection(Invoice $invoice, Collection $corrections): bool
    {
        return $corrections->contains(
            static fn (Invoice $correction): bool => ! $correction->is($invoice),
        );
    }

    private function releaseDocumentSlot(Invoice $invoice, ?OrderDocumentSlot $slot): void
    {
        $slot?->delete();
    }

    /** @param Collection<int, Invoice>|null $linkedDocuments */
    private function restoreSupersededProforma(
        Order $order,
        Invoice $invoice,
        ?Collection $linkedDocuments = null,
    ): ?Invoice {
        $linkedDocuments ??= Invoice::query()
            ->where('superseded_by_invoice_id', $invoice->getKey())
            ->lockForUpdate()
            ->get();
        $proforma = $this->validatedSupersededProforma($order, $linkedDocuments);

        if ($proforma === null) {
            return null;
        }

        $proforma->proforma_superseded_at = null;
        $proforma->superseded_by_invoice_id = null;
        $proforma->save();

        return $proforma;
    }

    /** @param Collection<int, Invoice> $linkedDocuments */
    private function validatedSupersededProforma(Order $order, Collection $linkedDocuments): ?Invoice
    {
        if ($linkedDocuments->count() > 1) {
            throw new InvoiceDomainException(
                'invoice_delete_inconsistent_document',
                'Nie można usunąć Faktury, ponieważ wykryto niespójne powiązania z Pro formą.',
            );
        }

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

        return $proforma;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, Collection<int, Invoice>>  $correctionsByOrder
     * @return Collection<int, InvoiceDeletionFacts>
     */
    private function buildDeletionFacts(
        Collection $invoices,
        Collection $orders,
        Collection $correctionsByOrder,
    ): Collection {
        $seriesIds = $invoices
            ->pluck('invoice_series_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $existingSeriesIds = InvoiceSeries::query()
            ->whereIntegerInRaw('id', $seriesIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();
        $selectedInvoiceIds = $invoices
            ->filter(static fn (Invoice $invoice): bool => $invoice->isInvoice())
            ->map(static fn (Invoice $invoice): int => (int) $invoice->getKey())
            ->values()
            ->all();
        $invoiceIdsWithCorrections = $selectedInvoiceIds === []
            ? collect()
            : Invoice::query()
                ->where('document_type', InvoiceDocumentType::Correction)
                ->whereIntegerInRaw('corrected_invoice_id', $selectedInvoiceIds)
                ->distinct()
                ->pluck('corrected_invoice_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip();
        $invoiceIdsWithBlockingKsefSubmissions = $selectedInvoiceIds === []
            ? collect()
            : KsefInvoiceSubmission::query()
                ->whereIntegerInRaw('invoice_id', $selectedInvoiceIds)
                ->whereIn('status', $this->blockingKsefSubmissionStatuses())
                ->distinct()
                ->pluck('invoice_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip();

        return $invoices->mapWithKeys(function (Invoice $invoice) use (
            $orders,
            $correctionsByOrder,
            $existingSeriesIds,
            $invoiceIdsWithCorrections,
            $invoiceIdsWithBlockingKsefSubmissions,
        ): array {
            /** @var Collection<int, Invoice> $corrections */
            $corrections = $correctionsByOrder->get($invoice->order_id, collect());

            return [
                $invoice->getKey() => new InvoiceDeletionFacts(
                    seriesExists: $invoice->invoice_series_id !== null
                        && $existingSeriesIds->has((int) $invoice->invoice_series_id),
                    orderExists: $invoice->order_id !== null
                        && $orders->has((int) $invoice->order_id),
                    hasCorrection: $invoiceIdsWithCorrections->has((int) $invoice->getKey()),
                    hasOtherCorrection: $invoice->isCorrection()
                        && $this->hasOtherCorrection($invoice, $corrections),
                    hasBlockingKsefSubmission: $invoiceIdsWithBlockingKsefSubmissions
                        ->has((int) $invoice->getKey()),
                ),
            ];
        });
    }

    /** @return array<int, string> */
    private function blockingKsefSubmissionStatuses(): array
    {
        return [
            'session_opened',
            'submitted',
            'processing',
            'accepted',
            'rejected',
            'uncertain',
        ];
    }

    /**
     * @param  array<int, int>  $invoiceIds
     * @return Collection<int, Collection<int, Invoice>>
     */
    private function lockSupersededProformasByInvoice(array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        return Invoice::query()
            ->whereIntegerInRaw('superseded_by_invoice_id', $invoiceIds)
            ->orderBy('superseded_by_invoice_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('superseded_by_invoice_id');
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
