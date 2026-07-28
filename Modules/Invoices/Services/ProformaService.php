<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\ProformaOperationStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;
use Modules\Invoices\ValueObjects\ProformaOperationResult;

class ProformaService
{
    public function __construct(
        private readonly InvoiceDocumentPreparationService $preparation,
        private readonly ProformaSourceSnapshotHasher $hasher,
        private readonly InvoiceRevisionBuilder $revisionBuilder,
        private readonly InvoiceNumberingService $numbering,
    ) {}

    public function createOrRefresh(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
    ): ProformaOperationResult {
        $this->assertNotLockedByInvoice($order);
        $existing = $this->existingProforma($order);

        if ($existing !== null) {
            $this->assertExistingProformaCanBeRefreshed($existing, $series);

            return $this->refresh($order, $existing, $context);
        }

        $this->assertNoOrphanedSlot($order);
        $this->assertInitialSeries($series);

        return $this->create($order, $series, $context);
    }

    /** Test seam proving that the outer transaction also owns numbering. */
    protected function afterNumberAssigned(Invoice $invoice): void {}

    private function create(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
    ): ProformaOperationResult {
        try {
            return DB::transaction(function () use ($order, $series, $context): ProformaOperationResult {
                $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $managedSeries = InvoiceSeries::query()->lockForUpdate()->findOrFail($series->getKey());
                $this->assertNotLockedByInvoice($managedOrder);

                if ($this->existingProforma($managedOrder) !== null) {
                    throw new InvoiceDomainException(
                        'invoice_document_slot_conflict',
                        'Nie można utworzyć dokumentu z powodu konfliktu operacji dla tego zamówienia.',
                    );
                }

                $this->assertNoOrphanedSlot($managedOrder);
                $this->assertInitialSeries($managedSeries);

                $slot = OrderDocumentSlot::query()->create([
                    'order_id' => $managedOrder->getKey(),
                    'document_type' => InvoiceDocumentType::Proforma,
                    'invoice_id' => null,
                ]);
                $invoice = Invoice::query()->create([
                    'order_id' => $managedOrder->getKey(),
                    'invoice_series_id' => $managedSeries->getKey(),
                    'document_type' => InvoiceDocumentType::Proforma,
                    'status' => InvoiceDocumentStatus::Draft,
                    'revision_number' => 1,
                    'last_refreshed_at' => null,
                ]);
                $prepared = $this->preparation->forCreation($managedOrder, $managedSeries, $context);
                $hash = $this->hasher->hash($prepared->hashPayload);
                $invoice->fill($prepared->invoiceAttributes);
                $invoice->source_snapshot_hash = $hash;
                $invoice->save();

                $invoice->items()->createMany($prepared->itemAttributes);
                $invoice = $this->assignNumber($invoice, $prepared->invoiceAttributes['issue_date']);
                $this->afterNumberAssigned($invoice);
                $invoice->status = InvoiceDocumentStatus::Issued;
                $invoice->save();
                $slot->update(['invoice_id' => $invoice->getKey()]);

                $invoice->unsetRelation('items');
                $this->revisionBuilder->create($invoice, $context);
                $this->createOrderEvent($managedOrder, $invoice, $managedSeries, $context, false);

                return new ProformaOperationResult(
                    $invoice->refresh()->load('items'),
                    ProformaOperationStatus::Created,
                );
            }, 3);
        } catch (QueryException $exception) {
            throw $this->mapCreationConflict($order, $exception);
        }
    }

    private function refresh(
        Order $order,
        Invoice $proforma,
        InvoiceOperationContext $context,
    ): ProformaOperationResult {
        try {
            return DB::transaction(function () use ($order, $proforma, $context): ProformaOperationResult {
                $managedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $managedProforma = Invoice::query()->lockForUpdate()->findOrFail($proforma->getKey());
                $managedSeries = InvoiceSeries::query()->lockForUpdate()->findOrFail($managedProforma->invoice_series_id);
                $this->assertNotLockedByInvoice($managedOrder);
                $this->assertSlotMatches($managedOrder, $managedProforma);

                if ($managedProforma->isProformaSuperseded()) {
                    throw $this->lockedByInvoice();
                }

                if (! $managedSeries->is_active) {
                    throw new InvoiceDomainException(
                        'proforma_series_inactive',
                        'Nie można odświeżyć Pro formy, ponieważ jej seria numeracji jest ukryta.',
                    );
                }

                $prepared = $this->preparation->forRefresh(
                    $managedOrder,
                    $managedSeries,
                    $managedProforma,
                    $context,
                );
                $newHash = $this->hasher->hash($prepared->hashPayload);

                if (hash_equals((string) $managedProforma->source_snapshot_hash, $newHash)) {
                    return new ProformaOperationResult(
                        $managedProforma->load('items'),
                        ProformaOperationStatus::Unchanged,
                    );
                }

                $previousHash = $managedProforma->source_snapshot_hash;
                $preserved = $managedProforma->only([
                    'invoice_series_id',
                    'number',
                    'sequence_number',
                    'numbering_period_key',
                    'number_format_snapshot',
                    'series_name_snapshot',
                    'issue_date',
                    'issued_at',
                ]);

                $managedProforma->items()->delete();
                $managedProforma->items()->createMany($prepared->itemAttributes);
                $managedProforma->fill($prepared->invoiceAttributes);
                $managedProforma->forceFill($preserved);
                $managedProforma->revision_number++;
                $managedProforma->source_snapshot_hash = $newHash;
                $managedProforma->last_refreshed_at = $context->occurredAt;
                $managedProforma->save();
                $managedProforma->unsetRelation('items');

                $this->revisionBuilder->create($managedProforma, $context);
                $this->createOrderEvent(
                    $managedOrder,
                    $managedProforma,
                    $managedSeries,
                    $context,
                    true,
                    $previousHash,
                );

                return new ProformaOperationResult(
                    $managedProforma->refresh()->load('items'),
                    ProformaOperationStatus::Refreshed,
                );
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new InvoiceDomainException(
                    'proforma_revision_conflict',
                    'Nie udało się zapisać nowej wersji Pro formy z powodu równoległej operacji.',
                    [],
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function assertNotLockedByInvoice(Order $order): void
    {
        if (Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Invoice)
            ->exists()) {
            throw $this->lockedByInvoice();
        }

        if ($this->existingProforma($order)?->isProformaSuperseded()) {
            throw $this->lockedByInvoice();
        }
    }

    private function assertInitialSeries(InvoiceSeries $series): void
    {
        if (! $series->is_active) {
            throw new InvoiceDomainException(
                'invoice_series_inactive',
                'Nie można wystawić dokumentu, ponieważ wybrana seria numeracji jest ukryta.',
            );
        }

        if ($series->document_type !== InvoiceDocumentType::Proforma) {
            throw new InvoiceDomainException(
                'invoice_series_type_mismatch',
                'Wybrana seria numeracji nie obsługuje tego typu dokumentu.',
            );
        }
    }

    private function assertExistingProformaCanBeRefreshed(Invoice $proforma, InvoiceSeries $series): void
    {
        if ($proforma->invoice_series_id !== $series->getKey()) {
            throw new InvoiceDomainException(
                'proforma_series_cannot_change',
                'Nie można zmienić serii numeracji istniejącej Pro formy.',
            );
        }

        $this->assertSlotMatches($proforma->order()->firstOrFail(), $proforma);

        if ($proforma->isProformaSuperseded()) {
            throw $this->lockedByInvoice();
        }

        if (! $series->is_active) {
            throw new InvoiceDomainException(
                'proforma_series_inactive',
                'Nie można odświeżyć Pro formy, ponieważ jej seria numeracji jest ukryta.',
            );
        }
    }

    private function assertNoOrphanedSlot(Order $order): void
    {
        $slot = OrderDocumentSlot::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Proforma)
            ->first();

        if ($slot !== null) {
            $this->logInconsistentSlot($slot);
            throw $this->inconsistentSlot();
        }
    }

    private function assertSlotMatches(Order $order, Invoice $proforma): void
    {
        $slot = OrderDocumentSlot::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Proforma)
            ->first();

        if ($slot === null
            || $slot->invoice_id !== $proforma->getKey()
            || $proforma->order_id !== $order->getKey()
            || $proforma->document_type !== InvoiceDocumentType::Proforma) {
            if ($slot !== null) {
                $this->logInconsistentSlot($slot);
            } else {
                Log::error('Wykryto Pro formę bez slotu dokumentu.', [
                    'order_id' => $order->getKey(),
                    'invoice_id' => $proforma->getKey(),
                ]);
            }

            throw $this->inconsistentSlot();
        }
    }

    private function existingProforma(Order $order): ?Invoice
    {
        return Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', InvoiceDocumentType::Proforma)
            ->first();
    }

    private function assignNumber(Invoice $invoice, string $issueDate): Invoice
    {
        try {
            return $this->numbering->assignNextNumber(
                $invoice,
                CarbonImmutable::parse($issueDate, config('app.timezone')),
            );
        } catch (DomainException|QueryException $exception) {
            throw new InvoiceDomainException(
                'invoice_numbering_failed',
                'Nie udało się nadać numeru dokumentu.',
                ['reason' => $exception->getMessage()],
                $exception,
            );
        }
    }

    private function createOrderEvent(
        Order $order,
        Invoice $invoice,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
        bool $refreshed,
        ?string $previousHash = null,
    ): void {
        $payload = [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->number,
            'revision_number' => $invoice->revision_number,
            'invoice_series_id' => $series->getKey(),
            'invoice_series_name' => $series->name,
            'total_gross' => $invoice->total_gross,
            'currency' => $invoice->currency,
            'source' => $context->source->value,
        ];

        if ($refreshed) {
            $payload['previous_source_snapshot_hash'] = $previousHash;
            $payload['new_source_snapshot_hash'] = $invoice->source_snapshot_hash;
        }

        $event = $order->events()->make([
            'event_type' => $refreshed ? 'proforma_refreshed' : 'proforma_issued',
            'title' => $refreshed ? 'Odświeżono Pro formę' : 'Wystawiono Pro formę',
            'description' => $refreshed
                ? 'Odświeżono dane Pro formy na podstawie zamówienia.'
                : 'Wystawiono Pro formę do zamówienia.',
            'payload' => $payload,
        ]);
        $event->created_at = $context->occurredAt;
        $event->updated_at = $context->occurredAt;
        $event->save();
    }

    private function mapCreationConflict(Order $order, QueryException $exception): InvoiceDomainException
    {
        if (! $this->isUniqueConstraintViolation($exception)) {
            throw $exception;
        }

        Log::warning('Konflikt równoległego tworzenia Pro formy.', [
            'order_id' => $order->getKey(),
            'error' => $exception->getMessage(),
        ]);

        return new InvoiceDomainException(
            'invoice_document_slot_conflict',
            'Nie można utworzyć dokumentu z powodu konfliktu operacji dla tego zamówienia.',
            [],
            $exception,
        );
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    private function logInconsistentSlot(OrderDocumentSlot $slot): void
    {
        Log::error('Wykryto niespójny slot Pro formy.', [
            'slot_id' => $slot->getKey(),
            'order_id' => $slot->order_id,
            'invoice_id' => $slot->invoice_id,
        ]);
    }

    private function inconsistentSlot(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_document_slot_inconsistent',
            'Nie można utworzyć dokumentu, ponieważ wykryto niespójne powiązanie dokumentu z zamówieniem.',
        );
    }

    private function lockedByInvoice(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'proforma_locked_by_invoice',
            'Nie można utworzyć ani odświeżyć Pro formy, ponieważ do zamówienia została już wystawiona Faktura VAT.',
        );
    }
}
