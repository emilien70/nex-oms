<?php

namespace Modules\Invoices\ValueObjects;

use Illuminate\Support\Collection;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\OrderDocumentSlot;

final readonly class CorrectionChainState
{
    /**
     * @param  Collection<int, Invoice>  $corrections
     * @param  Collection<int, Invoice>  $finalizedCorrections
     */
    public function __construct(
        public Invoice $rootInvoice,
        public Collection $corrections,
        public Collection $finalizedCorrections,
        public ?Invoice $finalizedTail,
        public ?Invoice $currentCorrection,
        public Invoice $effectiveSourceDocument,
        public ?OrderDocumentSlot $slot,
        public bool $legacyCurrentWithoutSlot,
    ) {}

    public function contains(Invoice $correction): bool
    {
        return $this->corrections->contains(
            static fn (Invoice $candidate): bool => $candidate->is($correction),
        );
    }
}
