<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class InvoiceFinalizationService
{
    public function __construct(
        private readonly CorrectionSourceStateService $sourceState,
    ) {}

    public function finalize(Invoice $document): Invoice
    {
        return DB::transaction(function () use ($document): Invoice {
            Order::query()->lockForUpdate()->findOrFail($document->order_id);

            if ($document->isCorrection()) {
                return $this->finalizeCorrection($document);
            }

            $managed = Invoice::query()->lockForUpdate()->findOrFail($document->getKey());
            $this->assertFinalizable($managed);

            if ($managed->isFinalized()) {
                return $managed;
            }

            $managed->finalized_at = now(config('app.timezone'));
            $managed->save();

            return $managed->refresh();
        }, 3);
    }

    private function finalizeCorrection(Invoice $document): Invoice
    {
        $source = Invoice::query()->lockForUpdate()->findOrFail($document->corrected_invoice_id);
        $this->sourceState->assertSourceInvoice($source);
        $chain = $this->sourceState->chain($source, true);
        $managed = $chain->corrections->first(
            static fn (Invoice $correction): bool => $correction->getKey() === $document->getKey(),
        );

        if (! $managed instanceof Invoice) {
            throw new InvoiceDomainException(
                'correction_chain_inconsistent',
                'Nie można zamknąć Korekty, ponieważ łańcuch Korekt jest niespójny.',
            );
        }

        $this->assertFinalizable($managed);

        if ($managed->isFinalized()) {
            return $managed;
        }

        if ($chain->currentCorrection === null
            || ! $chain->currentCorrection->is($managed)
            || $chain->slot === null
            || $chain->slot->invoice_id !== $managed->getKey()) {
            throw new InvoiceDomainException(
                'correction_document_slot_inconsistent',
                'Nie można zamknąć Korekty, ponieważ jej slot lub łańcuch jest niespójny.',
            );
        }

        $managed->finalized_at = now(config('app.timezone'));
        $managed->save();
        $chain->slot->delete();

        return $managed->refresh();
    }

    private function assertFinalizable(Invoice $document): void
    {
        if ($document->status !== InvoiceDocumentStatus::Issued
            || (! $document->isInvoice() && ! $document->isCorrection())) {
            throw new InvoiceDomainException(
                'invoice_finalization_not_allowed',
                'Zamknąć można wyłącznie wystawioną Fakturę VAT albo Korektę.',
            );
        }
    }
}
