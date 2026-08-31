<?php

namespace Modules\Ksef\Services;

use App\Support\CountryCatalog;
use App\Support\CurrencyCatalog;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionMapper;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionSourceReferenceResolver;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionData;

final class KsefFa3CorrectionEligibilityValidator
{
    public function __construct(
        private readonly KsefFa3CorrectionMapper $mapper,
        private readonly KsefFa3CorrectionSourceReferenceResolver $sourceReferences,
        private readonly CorrectionSourceStateService $sourceState,
        private readonly KsefFa3TaxTreatmentResolver $taxTreatments,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
        private readonly CorrectionTotalsCalculator $correctionTotals,
        private readonly CountryCatalog $countries,
    ) {}

    public function assertEligible(
        Invoice $correction,
        KsefSetting $settings,
        KsefFa3EligibilityMode $mode,
    ): void {
        if (! $correction->isCorrection() || $correction->status !== InvoiceDocumentStatus::Issued) {
            throw $this->error(
                'ksef_fa3_correction_document_not_supported',
                'Do przygotowania Korekty FA(3) kwalifikuje się wyłącznie wystawiona Korekta.',
            );
        }

        if ($mode === KsefFa3EligibilityMode::Authoritative && ! $correction->isFinalized()) {
            throw $this->error(
                'ksef_fa3_correction_document_not_finalized',
                'Korekta musi zostać zamknięta przed utworzeniem autorytatywnego dokumentu FA(3).',
            );
        }

        $mapped = $this->mapper->map($correction);
        if ($mapped->changedLines === [] && $mapped->buyerBefore === null) {
            throw $this->error(
                'ksef_fa3_correction_effect_missing',
                'Korekta nie zawiera rzeczywistej zmiany wymaganej dla FA(3).',
            );
        }

        $this->sourceReferences->resolve($correction, $settings->environment);
        $snapshot = $this->correctionSnapshot($correction);
        [$root, $source] = $this->assertSourceDocument($correction, $snapshot);
        $wdt = $this->assertLineTreatments($correction, $snapshot);
        $buyerAfter = $this->assertBuyerAfter($correction->buyer_snapshot, $settings);
        $buyerBefore = $this->assertBuyerBefore($mapped, $snapshot);

        if ($buyerBefore !== null && $this->identityKey($buyerBefore) !== $this->identityKey($buyerAfter)) {
            throw $this->error(
                'ksef_fa3_correction_buyer_identity_change_not_supported',
                'KSeF nie pozwala obsłużyć tej Korekty jako zmiany identyfikatora podatkowego nabywcy.',
            );
        }

        $this->assertWdtBuyer($wdt['before'], $buyerBefore ?? $buyerAfter);
        $this->assertWdtBuyer($wdt['after'], $buyerAfter);
        $this->assertSeller($correction, $root);
        $this->assertCurrency($correction, $root, $source, $mapped);
    }

    /** @return array<string, mixed> */
    private function correctionSnapshot(Invoice $correction): array
    {
        $snapshot = data_get($correction->tax_metadata_snapshot, 'ksef_correction');
        if (! is_array($snapshot)) {
            throw $this->error(
                'ksef_fa3_correction_snapshot_missing',
                'Korekta nie posiada snapshotu semantyki KSeF wymaganego dla FA(3).',
            );
        }

        if (($snapshot['version'] ?? null) !== 1) {
            throw $this->error(
                'ksef_fa3_correction_snapshot_version_unsupported',
                'Wersja snapshotu semantyki KSeF Korekty nie jest obsługiwana.',
            );
        }

        if (($snapshot['profile'] ?? null) !== 'correction') {
            throw $this->snapshotInvalid($correction, 'profile_invalid');
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: Invoice, 1: Invoice}
     */
    private function assertSourceDocument(Invoice $correction, array $snapshot): array
    {
        $root = $correction->correctedInvoice()->first();
        if (! $root instanceof Invoice) {
            throw $this->snapshotInvalid($correction, 'source_document_mismatch');
        }

        $chain = $this->sourceState->chain($root);
        $index = $chain->corrections->search(
            static fn (Invoice $candidate): bool => $candidate->is($correction),
        );
        if (! is_int($index)) {
            throw $this->snapshotInvalid($correction, 'source_document_mismatch');
        }

        $source = $index === 0 ? $root : $chain->corrections->get($index - 1);
        $stored = $snapshot['source_document'] ?? null;
        if (! $source instanceof Invoice
            || ! is_array($stored)
            || ($stored['invoice_id'] ?? null) !== $source->getKey()
            || ($stored['document_type'] ?? null) !== $source->document_type->value) {
            throw $this->snapshotInvalid($correction, 'source_document_mismatch');
        }

        return [$root, $source];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{before: bool, after: bool}
     */
    private function assertLineTreatments(Invoice $correction, array $snapshot): array
    {
        $records = $snapshot['line_treatments'] ?? null;
        if (! is_array($records)) {
            throw $this->taxSnapshotInvalid($correction, null, 'line_treatments_missing');
        }

        $indexed = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! is_int($record['invoice_item_id'] ?? null)) {
                throw $this->taxSnapshotInvalid($correction, null, 'invoice_item_id_invalid');
            }

            $itemId = $record['invoice_item_id'];
            if (array_key_exists($itemId, $indexed)) {
                throw $this->taxSnapshotInvalid($correction, null, 'duplicate_invoice_item_id');
            }

            $indexed[$itemId] = $record;
        }

        $items = $correction->items()->orderBy('position')->orderBy('id')->get();
        if (count($indexed) !== $items->count()) {
            throw $this->taxSnapshotInvalid($correction, null, 'line_treatment_coverage_mismatch');
        }

        $wdt = ['before' => false, 'after' => false];
        foreach ($items as $item) {
            $record = $indexed[$item->getKey()] ?? null;
            if (! is_array($record)) {
                throw $this->taxSnapshotInvalid($correction, $item, 'line_treatment_missing');
            }

            if (! array_key_exists('source_invoice_item_id', $record)
                || $record['source_invoice_item_id'] !== $item->source_invoice_item_id
                || ($record['position'] ?? null) !== $item->position) {
                throw $this->taxSnapshotInvalid($correction, $item, 'line_record_mismatch');
            }

            $before = $item->correction_before_snapshot;
            $after = $item->correction_after_snapshot;
            if (! is_array($before) || ! is_array($after)
                || ! is_array($record['before'] ?? null)
                || ! is_array($record['after'] ?? null)) {
                throw $this->taxSnapshotInvalid($correction, $item, 'line_semantics_missing');
            }

            foreach (['before' => $before, 'after' => $after] as $side => $lineSnapshot) {
                $semantics = $record[$side];
                $identity = $this->taxIdentity->key($this->taxIdentity->normalize(
                    $lineSnapshot['vat_rate'] ?? null,
                    $lineSnapshot['vat_code'] ?? null,
                ));
                if (($semantics['tax_identity'] ?? null) !== $identity) {
                    throw $this->taxSnapshotInvalid(
                        $correction,
                        $item,
                        'tax_identity_mismatch',
                        $side,
                        $lineSnapshot,
                    );
                }

                $this->assertTaxSemantics($correction, $item, $side, $lineSnapshot, $semantics);
                $wdt[$side] = $wdt[$side] || ($semantics['treatment'] ?? null) === 'wdt';
            }
        }

        return $wdt;
    }

    /**
     * @param  array<string, mixed>  $lineSnapshot
     * @param  array<string, mixed>  $semantics
     */
    private function assertTaxSemantics(
        Invoice $correction,
        InvoiceItem $item,
        string $side,
        array $lineSnapshot,
        array $semantics,
    ): void {
        $status = $semantics['status'] ?? null;
        if ($status === 'unresolved') {
            throw $this->error(
                'ksef_fa3_correction_tax_semantics_unresolved',
                'Nie można jednoznacznie ustalić historycznej semantyki VAT pozycji Korekty.',
                $this->itemMetadata($item, (string) ($semantics['reason'] ?? 'unresolved'), $side, $lineSnapshot),
            );
        }

        if ($status === 'unsupported') {
            $unsupportedCode = ($semantics['reason'] ?? null) === 'unsupported_vat_code';

            throw $this->error(
                $unsupportedCode
                    ? 'ksef_fa3_correction_unsupported_vat_code'
                    : 'ksef_fa3_correction_unsupported_vat_rate',
                'Korekta zawiera stawkę lub kod VAT nieobsługiwany przez aktualny profil FA(3).',
                $this->itemMetadata(
                    $item,
                    $unsupportedCode ? 'unsupported_vat_code' : (string) ($semantics['reason'] ?? 'unsupported_vat_rate'),
                    $side,
                    $lineSnapshot,
                ),
            );
        }

        if ($status !== 'resolved'
            || ! $this->taxTreatments->isCanonicalSnapshot(
                $lineSnapshot['vat_rate'] ?? null,
                $lineSnapshot['vat_code'] ?? null,
                $semantics,
            )) {
            throw $this->taxSnapshotInvalid(
                $correction,
                $item,
                'tax_treatment_inconsistent',
                $side,
                $lineSnapshot,
            );
        }
    }

    /** @return array<string, mixed> */
    private function assertBuyerAfter(mixed $buyer, KsefSetting $settings): array
    {
        if (! is_array($buyer)) {
            throw $this->buyerSnapshotInvalid('buyer_after_missing');
        }

        $identity = $buyer['tax_identity'] ?? null;
        $flags = $buyer['subject_flags'] ?? null;
        if (! is_array($identity) || ! is_array($flags)
            || ($identity['version'] ?? null) !== 1
            || ($flags['version'] ?? null) !== 1) {
            throw $this->buyerSnapshotInvalid('buyer_after_semantics_missing');
        }

        if (! in_array($identity['type'] ?? null, ['pl_nip', 'eu_vat', 'none'], true)
            || ($flags['jst'] ?? null) !== false
            || ($flags['vat_group'] ?? null) !== false) {
            throw $this->error(
                'ksef_fa3_correction_buyer_snapshot_unsupported',
                'Snapshot nabywcy zawiera semantykę nieobsługiwaną przez profil Korekty FA(3).',
            );
        }

        $resolved = $this->buyerIdentity->withSemantics($buyer);
        if ($identity !== $resolved['tax_identity'] || $flags !== $resolved['subject_flags']) {
            throw $this->buyerSnapshotInvalid('buyer_after_semantics_mismatch');
        }

        $this->assertBuyerBusinessData($buyer, 'after');
        $this->assertBuyerIdentity($identity, $flags, $settings);

        return $identity;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function assertBuyerBefore(
        KsefFa3CorrectionData $mapped,
        array $snapshot,
    ): ?array {
        if (! array_key_exists('buyer_before_semantics', $snapshot)) {
            throw $this->buyerSnapshotInvalid('buyer_before_missing');
        }

        $stored = $snapshot['buyer_before_semantics'] ?? null;
        if ($mapped->buyerBefore === null) {
            if ($stored !== null) {
                throw $this->buyerSnapshotInvalid('buyer_before_unexpected');
            }

            return null;
        }

        if (! is_array($stored)) {
            throw $this->buyerSnapshotInvalid('buyer_before_missing');
        }

        $resolved = $this->buyerIdentity->withSemantics($mapped->buyerBefore);
        if (($stored['tax_identity'] ?? null) !== $resolved['tax_identity']
            || ($stored['subject_flags'] ?? null) !== $resolved['subject_flags']) {
            throw $this->buyerSnapshotInvalid('buyer_before_semantics_mismatch');
        }

        try {
            $this->assertBuyerBusinessData($mapped->buyerBefore, 'before');
        } catch (InvoiceDomainException $exception) {
            throw $this->error(
                'ksef_fa3_correction_buyer_before_incomplete',
                'Dane nabywcy sprzed Korekty są niekompletne dla FA(3).',
                $exception->metadata(),
                $exception,
            );
        }

        $identity = $resolved['tax_identity'];
        if (($identity['status'] ?? null) !== 'resolved') {
            throw $this->error(
                'ksef_fa3_correction_buyer_before_incomplete',
                'Nie można jednoznacznie ustalić identyfikatora nabywcy sprzed Korekty.',
            );
        }

        return $identity;
    }

    /** @param array<string, mixed> $buyer */
    private function assertBuyerBusinessData(array $buyer, string $side): void
    {
        $name = $this->optionalString($buyer['company_name'] ?? null)
            ?? $this->optionalString($buyer['name'] ?? null);
        $country = $this->countries->normalize(
            is_string($buyer['country_code'] ?? null) ? $buyer['country_code'] : null,
        );
        $address = $this->addressLine($buyer);

        if ($name === null || ! $this->countries->exists($country) || $address === '') {
            throw $this->error(
                'ksef_fa3_correction_buyer_incomplete',
                'Snapshot nabywcy nie zawiera nazwy i adresu wymaganych dla Korekty FA(3).',
                [
                    'side' => $side,
                    'missing_fields' => array_values(array_filter([
                        $name === null ? 'buyer.name' : null,
                        $country === null ? 'buyer.country_code' : null,
                        $address === '' ? 'buyer.address' : null,
                    ])),
                    'invalid_fields' => $country !== null && ! $this->countries->exists($country)
                        ? ['buyer.country_code']
                        : [],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $flags
     */
    private function assertBuyerIdentity(
        array $identity,
        array $flags,
        KsefSetting $settings,
    ): void {
        if (($identity['status'] ?? null) !== 'resolved') {
            throw $this->error(
                'ksef_fa3_correction_buyer_identity_unresolved',
                'Nie można jednoznacznie ustalić identyfikatora podatkowego nabywcy dla Korekty FA(3).',
            );
        }

        if (($identity['type'] ?? null) === 'none' && ! $settings->send_without_buyer_nip) {
            throw $this->error(
                'ksef_fa3_correction_buyer_tax_id_required',
                'Konfiguracja KSeF wymaga identyfikatora podatkowego nabywcy.',
            );
        }

        if (! in_array($identity['type'] ?? null, ['pl_nip', 'eu_vat', 'none'], true)
            || ($flags['jst'] ?? null) !== false
            || ($flags['vat_group'] ?? null) !== false) {
            throw $this->error(
                'ksef_fa3_correction_buyer_snapshot_unsupported',
                'Snapshot nabywcy zawiera semantykę nieobsługiwaną przez profil Korekty FA(3).',
            );
        }
    }

    /** @param array<string, mixed> $identity */
    private function assertWdtBuyer(bool $hasWdt, array $identity): void
    {
        if ($hasWdt && (($identity['type'] ?? null) !== 'eu_vat'
            || ($identity['country_code'] ?? null) === 'PL')) {
            throw $this->error(
                'ksef_fa3_wdt_buyer_mismatch',
                'Pozycja WDT wymaga jednoznacznego numeru VAT UE nabywcy spoza Polski.',
            );
        }
    }

    private function assertSeller(Invoice $correction, Invoice $root): void
    {
        $seller = $correction->seller_snapshot;
        if (! is_array($seller)) {
            throw $this->sellerIncomplete();
        }

        $name = $this->optionalString($seller['name'] ?? null);
        $country = strtoupper((string) $this->optionalString($seller['country_code'] ?? null));
        $nip = $this->buyerIdentity->normalizePolishNip($seller['tax_id'] ?? null);
        if ($name === null || $country !== 'PL' || $nip === null || $this->addressLine($seller) === '') {
            throw $this->sellerIncomplete();
        }

        $rootNip = $this->buyerIdentity->normalizePolishNip(
            data_get($root->seller_snapshot, 'tax_id'),
        );
        if ($rootNip === null || ! hash_equals($rootNip, $nip)) {
            throw $this->error(
                'ksef_fa3_correction_seller_mismatch',
                'Sprzedawca Korekty nie odpowiada sprzedawcy Faktury pierwotnej.',
            );
        }

        if ($this->canonicalSeller($seller) !== $this->canonicalSeller($root->seller_snapshot ?? [])) {
            throw $this->error(
                'ksef_fa3_correction_seller_change_not_supported',
                'Zmiana danych sprzedawcy nie jest obsługiwana przez profil Korekty FA(3).',
            );
        }
    }

    /** @param array<string, mixed> $seller
     * @return array<string, string|null>
     */
    private function canonicalSeller(array $seller): array
    {
        return [
            'name' => $this->optionalString($seller['name'] ?? null),
            'tax_id' => $this->buyerIdentity->normalizePolishNip($seller['tax_id'] ?? null),
            'street' => $this->optionalString($seller['street'] ?? null),
            'building_number' => $this->optionalString($seller['building_number'] ?? null),
            'apartment_number' => $this->optionalString($seller['apartment_number'] ?? null),
            'postal_code' => $this->optionalString($seller['postal_code'] ?? null),
            'city' => $this->optionalString($seller['city'] ?? null),
            'country_code' => strtoupper((string) $this->optionalString($seller['country_code'] ?? null)),
        ];
    }

    private function assertCurrency(
        Invoice $correction,
        Invoice $root,
        Invoice $source,
        KsefFa3CorrectionData $mapped,
    ): void {
        $currency = strtoupper(trim((string) $correction->currency));
        if ($currency === ''
            || $currency !== strtoupper(trim((string) $root->currency))
            || $currency !== strtoupper(trim((string) $source->currency))) {
            throw $this->error(
                'ksef_fa3_correction_currency_invalid',
                'Waluta Korekty nie odpowiada walucie jej dokumentu źródłowego.',
            );
        }

        $difference = [
            'net' => $mapped->differenceTotals['net'],
            'vat' => $mapped->differenceTotals['vat'],
            'gross' => $mapped->differenceTotals['gross'],
            'tax_summary_snapshot' => $mapped->differenceTotals['taxSummary'],
        ];
        if ($currency === CurrencyCatalog::SYSTEM_CURRENCY
            || ! $this->correctionTotals->isMonetary($difference)) {
            return;
        }

        $metadata = $correction->tax_metadata_snapshot;
        if (! is_array($metadata)
            || ! is_array($metadata['currency_conversion'] ?? null)
            || $metadata['currency_conversion'] === []
            || ! is_array($metadata['converted_tax_summary'] ?? null)
            || $metadata['converted_tax_summary'] === []) {
            throw $this->error(
                'ksef_fa3_correction_currency_snapshot_missing',
                'Walutowa Korekta zmieniająca kwoty nie posiada zamrożonego przeliczenia do PLN.',
            );
        }
    }

    /** @param array<string, mixed> $identity
     * @return array{type: mixed, country_code: mixed, identifier: mixed}
     */
    private function identityKey(array $identity): array
    {
        return [
            'type' => $identity['type'] ?? null,
            'country_code' => $identity['country_code'] ?? null,
            'identifier' => $identity['identifier'] ?? null,
        ];
    }

    /** @param array<string, mixed> $party */
    private function addressLine(array $party): string
    {
        $street = $this->optionalString($party['street'] ?? null);
        $building = $this->optionalString($party['building_number'] ?? null);
        $apartment = $this->optionalString($party['apartment_number'] ?? null);
        $number = $building;
        if ($apartment !== null) {
            $number = $number !== null ? $number.'/'.$apartment : $apartment;
        }

        return trim(implode(' ', array_filter(
            [$street, $number],
            static fn (?string $value): bool => $value !== null,
        )));
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $lineSnapshot
     * @return array<string, mixed>
     */
    private function itemMetadata(
        InvoiceItem $item,
        string $reason,
        ?string $side = null,
        array $lineSnapshot = [],
    ): array {
        return array_filter([
            'invoice_item' => [
                'id' => $item->getKey(),
                'position' => $item->position,
                'name' => (string) $item->name,
            ],
            'side' => $side,
            'reason' => $reason,
            'vat_rate' => $this->optionalString($lineSnapshot['vat_rate'] ?? null),
            'vat_code' => $this->optionalString($lineSnapshot['vat_code'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function snapshotInvalid(Invoice $correction, string $reason): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_correction_snapshot_invalid',
            'Snapshot semantyki KSeF Korekty jest niekompletny lub niespójny.',
            [
                'correction_id' => $correction->getKey(),
                'reason' => $reason,
            ],
        );
    }

    private function taxSnapshotInvalid(
        Invoice $correction,
        ?InvoiceItem $item,
        string $reason,
        ?string $side = null,
        array $lineSnapshot = [],
    ): InvoiceDomainException {
        return $this->error(
            'ksef_fa3_correction_tax_snapshot_invalid',
            'Snapshot semantyki VAT Korekty jest niekompletny lub niespójny.',
            $item !== null
                ? $this->itemMetadata($item, $reason, $side, $lineSnapshot)
                : [
                    'correction_id' => $correction->getKey(),
                    'reason' => $reason,
                ],
        );
    }

    private function buyerSnapshotInvalid(string $reason): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_correction_buyer_snapshot_invalid',
            'Snapshot nabywcy Korekty jest niekompletny lub niespójny.',
            ['reason' => $reason],
        );
    }

    private function sellerIncomplete(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_correction_seller_incomplete',
            'Snapshot sprzedawcy Korekty nie zawiera kompletnych danych wymaganych dla FA(3).',
        );
    }

    /** @param array<string, mixed> $metadata */
    private function error(
        string $code,
        string $message,
        array $metadata = [],
        ?\Throwable $previous = null,
    ): InvoiceDomainException {
        return new InvoiceDomainException($code, $message, $metadata, $previous);
    }
}
