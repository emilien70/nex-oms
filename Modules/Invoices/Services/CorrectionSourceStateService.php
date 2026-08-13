<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\ValueObjects\CorrectionChainState;

class CorrectionSourceStateService
{
    public function assertSourceInvoice(Invoice $invoice): void
    {
        if ($invoice->document_type !== InvoiceDocumentType::Invoice
            || $invoice->status !== InvoiceDocumentStatus::Issued
            || $invoice->number === null
            || $invoice->issue_date === null) {
            throw new InvoiceDomainException(
                'correction_source_invalid',
                'Korektę można wystawić wyłącznie do wystawionej Faktury VAT.',
            );
        }
    }

    public function chain(Invoice $sourceInvoice, bool $lock = false): CorrectionChainState
    {
        $this->assertSourceInvoice($sourceInvoice);

        $correctionsQuery = Invoice::query()
            ->where('order_id', $sourceInvoice->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->orderBy('id');
        $slotQuery = OrderDocumentSlot::query()
            ->where('order_id', $sourceInvoice->order_id)
            ->where('document_type', InvoiceDocumentType::Correction);

        if ($lock) {
            $correctionsQuery->lockForUpdate();
            $slotQuery->lockForUpdate();
        }

        return $this->resolveChain(
            $sourceInvoice,
            $correctionsQuery->get(),
            $slotQuery->first(),
        );
    }

    /** @param Collection<int, Invoice> $corrections */
    public function resolveChain(
        Invoice $sourceInvoice,
        Collection $corrections,
        ?OrderDocumentSlot $slot,
    ): CorrectionChainState {
        $this->assertSourceInvoice($sourceInvoice);
        $corrections = $corrections->values();

        foreach ($corrections as $correction) {
            if ($correction->document_type !== InvoiceDocumentType::Correction
                || $correction->status !== InvoiceDocumentStatus::Issued
                || $correction->number === null
                || $correction->order_id !== $sourceInvoice->order_id
                || $correction->corrected_invoice_id !== $sourceInvoice->getKey()) {
                throw $this->inconsistentChain($sourceInvoice);
            }
        }

        $ordered = $this->linearize($sourceInvoice, $corrections);
        $current = $ordered->filter(
            static fn (Invoice $correction): bool => ! $correction->isFinalized(),
        );

        if ($current->count() > 1
            || ($current->isNotEmpty() && ! $current->first()->is($ordered->last()))) {
            throw $this->inconsistentChain($sourceInvoice);
        }

        /** @var Invoice|null $currentCorrection */
        $currentCorrection = $current->first();
        $finalizedCorrections = $ordered->filter(
            static fn (Invoice $correction): bool => $correction->isFinalized(),
        )->values();

        if ($currentCorrection !== null
            && $finalizedCorrections->count() !== $ordered->count() - 1) {
            throw $this->inconsistentChain($sourceInvoice);
        }

        /** @var Invoice|null $finalizedTail */
        $finalizedTail = $finalizedCorrections->last();
        $legacyCurrentWithoutSlot = false;

        if ($currentCorrection !== null) {
            if ($slot === null) {
                $legacyCurrentWithoutSlot = $ordered->count() === 1
                    && $currentCorrection->previous_correction_id === null;

                if (! $legacyCurrentWithoutSlot) {
                    throw $this->inconsistentSlot($sourceInvoice, null, $currentCorrection->getKey());
                }
            } elseif ($slot->invoice_id !== $currentCorrection->getKey()) {
                throw $this->inconsistentSlot(
                    $sourceInvoice,
                    $slot->invoice_id,
                    $currentCorrection->getKey(),
                );
            }
        } elseif ($slot !== null) {
            throw $this->inconsistentSlot($sourceInvoice, $slot->invoice_id, null);
        }

        return new CorrectionChainState(
            rootInvoice: $sourceInvoice,
            corrections: $ordered,
            finalizedCorrections: $finalizedCorrections,
            finalizedTail: $finalizedTail,
            currentCorrection: $currentCorrection,
            effectiveSourceDocument: $finalizedTail ?? $sourceInvoice,
            slot: $slot,
            legacyCurrentWithoutSlot: $legacyCurrentWithoutSlot,
        );
    }

    public function currentCorrection(Invoice $sourceInvoice, bool $lock = false): ?Invoice
    {
        return $this->chain($sourceInvoice, $lock)->currentCorrection;
    }

    public function effectiveDocument(Invoice $sourceInvoice, bool $lock = false): Invoice
    {
        return $this->chain($sourceInvoice, $lock)->effectiveSourceDocument;
    }

    /**
     * @return Collection<int, array{source_item_id: int, source_item: mixed, snapshot: array<string, mixed>}>
     */
    public function effectiveItems(
        Invoice $sourceInvoice,
        bool $lock = false,
        ?CorrectionChainState $state = null,
    ): Collection {
        $effective = ($state ?? $this->chain($sourceInvoice, $lock))->effectiveSourceDocument;
        $items = $effective->items()->orderBy('position')->orderBy('id');

        if ($lock) {
            $items->lockForUpdate();
        }

        return $items->get()->map(function ($item) use ($effective): array {
            $snapshot = $effective->isCorrection()
                ? $item->correction_after_snapshot
                : $this->snapshotFromItem($item);

            if (! is_array($snapshot)) {
                throw new InvoiceDomainException(
                    'correction_source_incomplete',
                    'Nie można wystawić Korekty, ponieważ skuteczny stan pozycji dokumentu jest niekompletny.',
                );
            }

            return [
                'source_item_id' => (int) $item->getKey(),
                'source_item' => $item,
                'snapshot' => $snapshot,
            ];
        })->values();
    }

    /** @return array<string, mixed> */
    public function effectiveBuyer(
        Invoice $sourceInvoice,
        bool $lock = false,
        ?CorrectionChainState $state = null,
    ): array {
        $buyer = ($state ?? $this->chain($sourceInvoice, $lock))->effectiveSourceDocument->buyer_snapshot;

        if (! is_array($buyer)) {
            throw new InvoiceDomainException(
                'correction_source_incomplete',
                'Nie można wystawić Korekty, ponieważ dane nabywcy dokumentu są niekompletne.',
            );
        }

        return $buyer;
    }

    /** @param Collection<int, Invoice> $corrections */
    private function linearize(Invoice $sourceInvoice, Collection $corrections): Collection
    {
        if ($corrections->isEmpty()) {
            return collect();
        }

        $roots = $corrections->filter(
            static fn (Invoice $correction): bool => $correction->previous_correction_id === null,
        );

        if ($roots->count() !== 1) {
            throw $this->inconsistentChain($sourceInvoice);
        }

        $children = $corrections->groupBy('previous_correction_id');
        $ordered = collect();
        /** @var Invoice|null $cursor */
        $cursor = $roots->first();

        while ($cursor !== null) {
            if ($ordered->contains(fn (Invoice $visited): bool => $visited->is($cursor))) {
                throw $this->inconsistentChain($sourceInvoice);
            }

            $ordered->push($cursor);
            /** @var Collection<int, Invoice> $next */
            $next = $children->get($cursor->getKey(), collect());

            if ($next->count() > 1) {
                throw $this->inconsistentChain($sourceInvoice);
            }

            $cursor = $next->first();
        }

        if ($ordered->count() !== $corrections->count()) {
            throw $this->inconsistentChain($sourceInvoice);
        }

        return $ordered->values();
    }

    /** @return array<string, mixed> */
    private function snapshotFromItem(mixed $item): array
    {
        return [
            'line_type' => $item->line_type->value,
            'position' => (int) $item->position,
            'name' => $item->name,
            'description' => $item->description,
            'unit_name' => $item->unit_name,
            'quantity' => (string) $item->quantity,
            'unit_price_net' => (string) $item->unit_price_net,
            'unit_price_gross' => (string) $item->unit_price_gross,
            'total_net' => (string) $item->total_net,
            'total_vat' => (string) $item->total_vat,
            'total_gross' => (string) $item->total_gross,
            'vat_rate' => $item->vat_rate !== null ? (string) $item->vat_rate : null,
            'vat_code' => $item->vat_code,
        ];
    }

    private function inconsistentChain(Invoice $sourceInvoice): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'correction_chain_inconsistent',
            'Nie można obsłużyć Korekty, ponieważ łańcuch Korekt jest niespójny.',
            ['source_invoice_id' => $sourceInvoice->getKey()],
        );
    }

    private function inconsistentSlot(
        Invoice $sourceInvoice,
        ?int $slotInvoiceId,
        ?int $expectedInvoiceId,
    ): InvoiceDomainException {
        return new InvoiceDomainException(
            'correction_document_slot_inconsistent',
            'Nie można obsłużyć Korekty, ponieważ slot Korekty dla zamówienia jest niespójny.',
            [
                'source_invoice_id' => $sourceInvoice->getKey(),
                'slot_invoice_id' => $slotInvoiceId,
                'expected_invoice_id' => $expectedInvoiceId,
            ],
        );
    }
}
