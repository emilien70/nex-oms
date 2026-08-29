<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Enums\KsefZeroVatClassification;

class KsefFa3CorrectionSemanticSnapshotService
{
    private const BUYER_FIELDS = [
        'name',
        'company_name',
        'tax_id',
        'street',
        'building_number',
        'apartment_number',
        'postal_code',
        'city',
        'country_code',
    ];

    public function __construct(
        private readonly CorrectionSourceStateService $sourceState,
        private readonly KsefSettingsService $settings,
        private readonly KsefFa3TaxTreatmentResolver $taxTreatments,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
    ) {}

    public function initialize(Invoice $correction): void
    {
        $this->materialize($correction);
    }

    public function refresh(Invoice $correction): void
    {
        $this->materialize($correction);
    }

    private function materialize(Invoice $correction): void
    {
        if (! $correction->isCorrection() || $correction->status !== InvoiceDocumentStatus::Issued) {
            throw $this->sourceInvalid($correction);
        }

        $correction->loadMissing('correctedInvoice');
        $root = $correction->correctedInvoice;
        if (! $root instanceof Invoice) {
            throw $this->sourceInvalid($correction);
        }

        $chain = $this->sourceState->chain($root);
        $correctionIndex = $chain->corrections->search(
            static fn (Invoice $candidate): bool => $candidate->is($correction),
        );
        if (! is_int($correctionIndex)) {
            throw $this->sourceInvalid($correction);
        }

        $sourceDocument = $correctionIndex === 0
            ? $root
            : $chain->corrections->get($correctionIndex - 1);
        if (! $sourceDocument instanceof Invoice
            || ($correctionIndex === 0 && $correction->previous_correction_id !== null)
            || ($correctionIndex > 0 && $correction->previous_correction_id !== $sourceDocument->getKey())) {
            throw $this->sourceInvalid($correction);
        }

        $sourceItems = $sourceDocument->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (InvoiceItem $item): int => (int) $item->getKey());
        $currentItems = $correction->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $sourceTreatments = $this->sourceTreatments($sourceDocument);
        $zeroClassification = $this->zeroClassification();
        $usedSourceItemIds = [];

        $lineTreatments = $currentItems->map(function (InvoiceItem $item) use (
            $sourceItems,
            $sourceTreatments,
            $zeroClassification,
            &$usedSourceItemIds,
        ): array {
            $beforeSnapshot = $item->correction_before_snapshot;
            $afterSnapshot = $item->correction_after_snapshot;
            if (! is_array($beforeSnapshot) || ! is_array($afterSnapshot)) {
                throw $this->sourceInvalid($item->invoice);
            }

            $beforeIdentity = $this->taxIdentityKey($beforeSnapshot);
            $afterIdentity = $this->taxIdentityKey($afterSnapshot);
            $sourceItemId = $item->source_invoice_item_id !== null
                ? (int) $item->source_invoice_item_id
                : null;

            if ($sourceItemId === null) {
                $after = $this->taxTreatments->resolveSnapshot(
                    $afterSnapshot['vat_rate'] ?? null,
                    $afterSnapshot['vat_code'] ?? null,
                    $zeroClassification,
                );
                $before = $after;
            } else {
                if (! $sourceItems->has($sourceItemId) || isset($usedSourceItemIds[$sourceItemId])) {
                    throw $this->sourceInvalid($item->invoice);
                }
                $usedSourceItemIds[$sourceItemId] = true;
                $before = $this->historicalSemantics(
                    $sourceTreatments->get($sourceItemId),
                    $beforeIdentity,
                );
                $after = $beforeIdentity === $afterIdentity
                    ? $before
                    : $this->taxTreatments->resolveSnapshot(
                        $afterSnapshot['vat_rate'] ?? null,
                        $afterSnapshot['vat_code'] ?? null,
                        $zeroClassification,
                    );
            }

            return [
                'invoice_item_id' => (int) $item->getKey(),
                'source_invoice_item_id' => $sourceItemId,
                'position' => (int) $item->position,
                'before' => $before,
                'after' => $after,
            ];
        })->all();

        if (count($lineTreatments) !== $currentItems->count()) {
            throw $this->sourceInvalid($correction);
        }

        $buyerBefore = data_get($correction->order_snapshot, 'correction.buyer_before');
        $buyerAfter = $correction->buyer_snapshot;
        if (! is_array($buyerBefore) || ! is_array($buyerAfter)) {
            throw $this->sourceInvalid($correction);
        }

        $buyerAfter = $this->buyerIdentity->withSemantics($buyerAfter);
        $buyerBeforeSemantics = $this->canonicalBuyer($buyerBefore) !== $this->canonicalBuyer($buyerAfter)
            ? $this->buyerSemantics($buyerBefore)
            : null;
        $metadata = $correction->tax_metadata_snapshot ?? [];
        $metadata['ksef_correction'] = [
            'version' => 1,
            'profile' => 'correction',
            'source_document' => [
                'invoice_id' => (int) $sourceDocument->getKey(),
                'document_type' => $sourceDocument->document_type->value,
            ],
            'buyer_before_semantics' => $buyerBeforeSemantics,
            'line_treatments' => $lineTreatments,
        ];

        $correction->forceFill([
            'buyer_snapshot' => $buyerAfter,
            'tax_metadata_snapshot' => $metadata,
        ])->save();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sourceTreatments(Invoice $sourceDocument): Collection
    {
        $entries = $sourceDocument->isCorrection()
            ? data_get($sourceDocument->tax_metadata_snapshot, 'ksef_correction.line_treatments')
            : data_get($sourceDocument->tax_metadata_snapshot, 'ksef_tax.line_treatments');

        if (! is_array($entries)) {
            return collect();
        }

        $indexed = collect();
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_numeric($entry['invoice_item_id'] ?? null)) {
                continue;
            }

            $itemId = (int) $entry['invoice_item_id'];
            if ($indexed->has($itemId)) {
                throw $this->sourceInvalid($sourceDocument);
            }

            $semantics = $sourceDocument->isCorrection()
                ? ($entry['after'] ?? null)
                : collect($entry)->except(['invoice_item_id', 'position'])->all();
            $indexed->put($itemId, is_array($semantics) ? $semantics : false);
        }

        return $indexed;
    }

    /** @param array<string, mixed>|false|null $source */
    private function historicalSemantics(array|false|null $source, ?string $identity): array
    {
        if ($source === null) {
            return $this->unresolved($identity, 'source_semantics_missing');
        }

        if ($source === false
            || ($source['tax_identity'] ?? null) !== $identity
            || ! $this->validHistoricalSemantics($source)) {
            return $this->unresolved($identity, 'source_semantics_inconsistent');
        }

        return $source;
    }

    /** @param array<string, mixed> $semantics */
    private function validHistoricalSemantics(array $semantics): bool
    {
        $status = $semantics['status'] ?? null;
        if (! is_string($semantics['tax_identity'] ?? null)
            || ! in_array($status, ['resolved', 'unsupported', 'unresolved'], true)) {
            return false;
        }

        return $status === 'resolved'
            ? is_string($semantics['treatment'] ?? null) && is_string($semantics['fa3_rate'] ?? null)
            : is_string($semantics['reason'] ?? null);
    }

    /** @param array<string, mixed> $snapshot */
    private function taxIdentityKey(array $snapshot): ?string
    {
        return $this->taxIdentity->key($this->taxIdentity->normalize(
            $snapshot['vat_rate'] ?? null,
            $snapshot['vat_code'] ?? null,
        ));
    }

    /** @return array<string, mixed> */
    private function unresolved(?string $identity, string $reason): array
    {
        return [
            'tax_identity' => $identity,
            'status' => 'unresolved',
            'reason' => $reason,
        ];
    }

    private function zeroClassification(): KsefZeroVatClassification
    {
        $classification = $this->settings->get()->zero_vat_classification;

        return $classification instanceof KsefZeroVatClassification
            ? $classification
            : KsefZeroVatClassification::Wdt;
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    private function buyerSemantics(array $buyer): array
    {
        $buyer = $this->buyerIdentity->withSemantics($buyer);

        return [
            'tax_identity' => $buyer['tax_identity'],
            'subject_flags' => $buyer['subject_flags'],
        ];
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, string|null>
     */
    private function canonicalBuyer(array $buyer): array
    {
        $canonical = [];

        foreach (self::BUYER_FIELDS as $field) {
            $value = $this->optionalString($buyer[$field] ?? null);
            $canonical[$field] = $field === 'country_code' && $value !== null
                ? strtoupper($value)
                : $value;
        }

        return $canonical;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function sourceInvalid(Invoice $correction): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_correction_semantic_source_invalid',
            'Nie można zapisać semantyki KSeF Korekty z powodu niespójnego źródła.',
            ['correction_id' => $correction->getKey()],
        );
    }
}
