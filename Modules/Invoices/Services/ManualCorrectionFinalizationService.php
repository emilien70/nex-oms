<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Models\KsefInvoiceProvenance;

final class ManualCorrectionFinalizationService
{
    public function __construct(
        private readonly ManualCorrectionFinalizationPolicy $policy,
        private readonly InvoiceFinalizationService $finalization,
        private readonly CorrectionSourceStateService $sourceState,
    ) {}

    public function finalize(
        Invoice $correction,
        int $expectedLockVersion,
        KsefEnvironment $expectedEnvironment,
        bool $confirmedOutsideKsef,
    ): Invoice {
        return DB::transaction(function () use ($correction, $expectedLockVersion, $expectedEnvironment, $confirmedOutsideKsef): Invoice {
            Order::query()->lockForUpdate()->findOrFail($correction->order_id);
            $managed = Invoice::query()->lockForUpdate()->findOrFail($correction->getKey());
            if (! $managed->isCorrection() || $managed->order_id !== $correction->order_id || ! $managed->isIssued()) {
                throw new InvoiceDomainException('correction_manual_finalization_document_invalid', 'Ręczne zamknięcie jest dostępne wyłącznie dla wystawionej Korekty.');
            }
            if ($managed->lock_version !== $expectedLockVersion) {
                throw new InvoiceDomainException('correction_manual_finalization_conflict', 'Korekta została w międzyczasie zmieniona. Odśwież formularz i ponownie sprawdź dane.');
            }
            if (! $confirmedOutsideKsef) {
                throw new InvoiceDomainException('correction_manual_finalization_confirmation_required', 'Wymagane jest jawne potwierdzenie wystawienia tej Korekty poza KSeF.');
            }

            if ($managed->isFinalized()) {
                $outside = KsefInvoiceProvenance::query()->where('invoice_id', $managed->getKey())
                    ->where('environment', $expectedEnvironment->value)
                    ->where('provenance', KsefInvoiceProvenanceType::OutsideKsef->value)->lockForUpdate()->exists();
                $root = $managed->correctedInvoice()->lockForUpdate()->first();
                if (! $outside || $root === null || ! $this->sourceState->chain($root, true)->contains($managed)) {
                    throw new InvoiceDomainException('correction_finalized', 'Korekta została już zamknięta.');
                }

                return $managed;
            }

            $environment = $this->policy->assertAllowed($managed, true);
            if ($environment !== $expectedEnvironment) {
                throw new InvoiceDomainException('correction_manual_finalization_environment_changed', 'Środowisko KSeF zmieniło się. Odśwież formularz i ponownie sprawdź dane.');
            }

            $finalized = $this->finalization->finalize($managed);
            // Record the operator's separate decision for this correction, not inherited provenance.
            KsefInvoiceProvenance::query()->firstOrCreate([
                'invoice_id' => $finalized->getKey(),
                'environment' => $environment->value,
            ], [
                'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
                'recorded_at' => now('UTC'),
            ]);

            return $finalized;
        }, 3);
    }
}
