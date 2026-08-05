<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

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

    public function latestIssuedCorrection(Invoice $sourceInvoice, bool $lock = false): ?Invoice
    {
        $query = Invoice::query()
            ->where('corrected_invoice_id', $sourceInvoice->getKey())
            ->where('document_type', InvoiceDocumentType::Correction)
            ->where('status', InvoiceDocumentStatus::Issued)
            ->orderByDesc('issued_at')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function effectiveDocument(Invoice $sourceInvoice, bool $lock = false): Invoice
    {
        $this->assertSourceInvoice($sourceInvoice);

        return $this->latestIssuedCorrection($sourceInvoice, $lock) ?? $sourceInvoice;
    }

    /**
     * @return Collection<int, array{source_item_id: int, source_item: mixed, snapshot: array<string, mixed>}>
     */
    public function effectiveItems(Invoice $sourceInvoice, bool $lock = false): Collection
    {
        $effective = $this->effectiveDocument($sourceInvoice, $lock);
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
    public function effectiveBuyer(Invoice $sourceInvoice, bool $lock = false): array
    {
        $buyer = $this->effectiveDocument($sourceInvoice, $lock)->buyer_snapshot;

        if (! is_array($buyer)) {
            throw new InvoiceDomainException(
                'correction_source_incomplete',
                'Nie można wystawić Korekty, ponieważ dane nabywcy dokumentu są niekompletne.',
            );
        }

        return $buyer;
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
}
