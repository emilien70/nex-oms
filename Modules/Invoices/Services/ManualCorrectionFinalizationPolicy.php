<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefOfflineTechnicalCorrection;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;

final class ManualCorrectionFinalizationPolicy
{
    public function __construct(private readonly CorrectionSourceStateService $sourceState) {}

    public function availableEnvironment(Invoice $correction): ?KsefEnvironment
    {
        return $this->review($correction)['environment'];
    }

    /** @return array{environment: ?KsefEnvironment, rootInvoice: ?Invoice, rootConfirmationRequired: bool, blockReason: ?string} */
    public function review(Invoice $correction): array
    {
        try {
            return [...$this->inspect($correction), 'blockReason' => null];
        } catch (InvoiceDomainException $exception) {
            return ['environment' => null, 'rootInvoice' => null, 'rootConfirmationRequired' => false,
                'blockReason' => $exception->getMessage()];
        }
    }

    public function assertAllowed(Invoice $correction, bool $lock = false): KsefEnvironment
    {
        $context = $this->inspect($correction, $lock);
        if ($context['rootConfirmationRequired']) {
            throw $this->blocked('outside_ksef_unconfirmed', 'Faktura pierwotna wymaga zamknięcia i jawnego oznaczenia OutsideKsef w aktywnym środowisku.');
        }

        return $context['environment'];
    }

    /**
     * Admission only; an unclassified root still requires separate operator confirmation.
     *
     * @return array{environment: KsefEnvironment, rootInvoice: Invoice, rootConfirmationRequired: bool}
     */
    public function inspect(Invoice $correction, bool $lock = false): array
    {
        if (! $correction->isCorrection() || ! $correction->isIssued() || $correction->isFinalized()) {
            throw $this->blocked('document_invalid', 'Ręcznie zamknąć można wyłącznie wystawioną, niezamkniętą Korektę.');
        }

        $root = $correction->correctedInvoice()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($root === null) {
            throw $this->blocked('chain_invalid', 'Brakuje Faktury pierwotnej Korekty.');
        }
        $chain = $this->sourceState->chain($root, $lock);
        if (! $chain->currentCorrection?->is($correction)
            || $chain->slot?->invoice_id !== $correction->getKey()) {
            throw $this->blocked('chain_invalid', 'Slot lub łańcuch Korekt jest niespójny.');
        }

        $settings = KsefSetting::query()->where('singleton_key', KsefSetting::SINGLETON_KEY)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($settings === null) {
            throw $this->blocked('outside_ksef_unconfirmed', 'Nie można potwierdzić środowiska i pochodzenia dokumentów poza KSeF.');
        }

        $documents = collect([$root])->merge($chain->corrections);
        $seriesIds = $documents->pluck('invoice_series_id')->unique()->sort()->values();
        $series = InvoiceSeries::query()->whereIn('id', $seriesIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        foreach ($documents as $document) {
            if ($series->get($document->invoice_series_id)?->document_type !== $document->document_type
                || ! is_string($document->number) || trim($document->number) === ''
                || $document->issue_date === null || $document->sequence_number === null
                || ! is_string($document->numbering_period_key) || trim($document->numbering_period_key) === '') {
                throw $this->blocked('document_invalid', 'Dane lub seria dokumentów w łańcuchu Korekt są niespójne.');
            }
        }

        // Lock parent series too: enabling KSeF may insert a previously missing settings row.
        if (KsefSeriesSetting::query()->whereIn('invoice_series_id', $seriesIds)->where('is_enabled', true)
            ->when($lock, fn ($query) => $query->lockForUpdate())->exists()) {
            throw $this->blocked('ksef_series_enabled', 'Seria Faktury lub Korekty jest obsługiwana przez KSeF. Ręczne zamknięcie poza KSeF jest zablokowane.');
        }

        $ids = $documents->pluck('id');
        if (KsefInvoiceSubmission::query()->whereIn('invoice_id', $ids)
            ->when($lock, fn ($query) => $query->lockForUpdate())->exists()) {
            throw $this->blocked('ksef_history_exists', 'Faktura lub łańcuch Korekt posiada historię transmisji KSeF.');
        }
        if (KsefOfflineIssuance::query()->whereIn('invoice_id', $ids)
            ->when($lock, fn ($query) => $query->lockForUpdate())->exists()
            || KsefOfflineTechnicalCorrection::query()->whereIn('invoice_id', $ids)
                ->when($lock, fn ($query) => $query->lockForUpdate())->exists()) {
            throw $this->blocked('offline_history_exists', 'Faktura lub łańcuch Korekt posiada dokumenty Offline.');
        }

        $provenances = KsefInvoiceProvenance::query()->whereIn('invoice_id', $ids)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $outsideSources = $chain->finalizedCorrections
            ->every(fn (Invoice $document): bool => $document->isFinalized()
                && $provenances->contains(fn (KsefInvoiceProvenance $provenance): bool => $provenance->invoice_id === $document->getKey()
                    && $provenance->environment === $settings->environment
                    && $provenance->provenance === KsefInvoiceProvenanceType::OutsideKsef));
        if (! $outsideSources
            || $provenances->contains(fn (KsefInvoiceProvenance $provenance): bool => $provenance->provenance !== KsefInvoiceProvenanceType::OutsideKsef)) {
            throw $this->blocked('outside_ksef_unconfirmed', 'Wcześniejsze Korekty wymagają własnego jawnego oznaczenia OutsideKsef w aktywnym środowisku.');
        }

        $rootOutside = $provenances->contains(fn (KsefInvoiceProvenance $provenance): bool => $provenance->invoice_id === $root->getKey()
            && $provenance->environment === $settings->environment
            && $provenance->provenance === KsefInvoiceProvenanceType::OutsideKsef);
        if ($rootOutside && ! $root->isFinalized()) {
            throw $this->blocked('document_invalid', 'Oznaczenie OutsideKsef Faktury pierwotnej jest niespójne z jej stanem zamknięcia.');
        }

        return ['environment' => $settings->environment, 'rootInvoice' => $root,
            'rootConfirmationRequired' => ! $rootOutside];
    }

    private function blocked(string $reason, string $message): InvoiceDomainException
    {
        return new InvoiceDomainException('correction_manual_finalization_'.$reason, $message);
    }
}
