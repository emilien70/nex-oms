<?php

namespace Modules\Ksef\Services;

use App\Support\CountryCatalog;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\Fa3\KsefFa3OptionalBlocksResolver;

class KsefFa3EligibilityValidator
{
    public function __construct(
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly KsefFa3TaxTreatmentResolver $taxTreatments,
        private readonly CountryCatalog $countries,
        private readonly KsefFa3OptionalBlocksResolver $optionalBlocks,
    ) {}

    public function assertEligible(
        Invoice $invoice,
        KsefSetting $settings,
        KsefFa3EligibilityMode $mode,
    ): void {
        if (! $invoice->isInvoice() || $invoice->status !== InvoiceDocumentStatus::Issued) {
            throw $this->error(
                'ksef_fa3_document_not_supported',
                'Do przygotowania FA(3) kwalifikuje się wyłącznie wystawiona Faktura VAT.',
            );
        }

        if ($mode === KsefFa3EligibilityMode::Authoritative && ! $invoice->isFinalized()) {
            throw $this->error(
                'ksef_fa3_document_not_finalized',
                'Faktura musi zostać zamknięta przed utworzeniem autorytatywnego dokumentu FA(3).',
            );
        }

        $this->assertSeller($invoice->seller_snapshot ?? []);
        $buyerIdentity = $this->assertBuyer($invoice->buyer_snapshot ?? [], $settings);
        $treatments = $this->assertTaxSnapshot($invoice);

        if (collect($treatments)->contains(
            static fn (array $treatment): bool => ($treatment['treatment'] ?? null) === 'wdt',
        ) && (($buyerIdentity['type'] ?? null) !== 'eu_vat'
            || ($buyerIdentity['country_code'] ?? null) === 'PL')) {
            throw $this->error(
                'ksef_fa3_wdt_buyer_mismatch',
                'Pozycja WDT wymaga jednoznacznego numeru VAT UE nabywcy spoza Polski.',
            );
        }

        $this->optionalBlocks->resolve($invoice);
    }

    /** @param array<string, mixed> $seller */
    private function assertSeller(array $seller): void
    {
        $name = trim((string) ($seller['name'] ?? ''));
        $country = strtoupper(trim((string) ($seller['country_code'] ?? '')));
        $taxId = $this->optionalString($seller['tax_id'] ?? null);
        $nip = $this->buyerIdentity->normalizePolishNip($seller['tax_id'] ?? null);
        $addressLine = trim(implode(' ', array_filter([
            $seller['street'] ?? null,
            $seller['building_number'] ?? null,
            $seller['apartment_number'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        if ($name === '' || $country !== 'PL' || $nip === null || $addressLine === '') {
            $missingFields = array_values(array_filter([
                $name === '' ? 'seller.name' : null,
                $country === '' ? 'seller.country_code' : null,
                $taxId === null ? 'seller.tax_id' : null,
                $addressLine === '' ? 'seller.address' : null,
            ]));
            $invalidFields = array_values(array_filter([
                $country !== '' && $country !== 'PL' ? 'seller.country_code' : null,
                $taxId !== null && $nip === null ? 'seller.tax_id' : null,
            ]));

            throw $this->error(
                'ksef_fa3_seller_incomplete',
                'Snapshot sprzedawcy nie zawiera kompletnych danych wymaganych dla FA(3).',
                [
                    'missing_fields' => $missingFields,
                    'invalid_fields' => $invalidFields,
                ],
            );
        }
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    private function assertBuyer(array $buyer, KsefSetting $settings): array
    {
        $identity = $buyer['tax_identity'] ?? null;
        $flags = $buyer['subject_flags'] ?? null;

        if (! is_array($identity) || ! is_array($flags)) {
            throw $this->error(
                'ksef_fa3_buyer_snapshot_missing',
                'Snapshot nabywcy nie zawiera semantyki wymaganej dla FA(3).',
            );
        }

        if (($identity['version'] ?? null) !== 1 || ($flags['version'] ?? null) !== 1) {
            throw $this->error(
                'ksef_fa3_snapshot_version_unsupported',
                'Wersja snapshotu semantyki KSeF nie jest obsługiwana.',
            );
        }

        $companyName = $this->optionalString($buyer['company_name'] ?? null);
        $name = $companyName ?? $this->optionalString($buyer['name'] ?? null);
        $country = $this->countries->normalize(
            is_string($buyer['country_code'] ?? null) ? $buyer['country_code'] : null,
        );
        $addressLine = $this->addressLine($buyer);

        if ($name === null || ! $this->countries->exists($country) || $addressLine === '') {
            $missingFields = array_values(array_filter([
                $name === null ? 'buyer.name' : null,
                $country === null ? 'buyer.country_code' : null,
                $addressLine === '' ? 'buyer.address' : null,
            ]));
            $invalidFields = $country !== null && ! $this->countries->exists($country)
                ? ['buyer.country_code']
                : [];

            throw $this->error(
                'ksef_fa3_buyer_incomplete',
                'Snapshot nabywcy nie zawiera nazwy i adresu wymaganych dla zwykłej Faktury FA(3).',
                [
                    'missing_fields' => $missingFields,
                    'invalid_fields' => $invalidFields,
                ],
            );
        }

        if (($identity['status'] ?? null) !== 'resolved') {
            throw $this->error(
                'ksef_fa3_buyer_identity_unresolved',
                'Nie można jednoznacznie ustalić identyfikatora podatkowego nabywcy dla FA(3).',
            );
        }

        if ($this->buyerIdentity->resolve($buyer) !== $identity) {
            throw $this->error(
                'ksef_fa3_buyer_snapshot_invalid',
                'Snapshot identyfikatora podatkowego nabywcy jest niespójny z danymi Faktury.',
            );
        }

        if (($identity['type'] ?? null) === 'none' && ! $settings->send_without_buyer_nip) {
            throw $this->error(
                'ksef_fa3_buyer_tax_id_required',
                'Konfiguracja KSeF wymaga identyfikatora podatkowego nabywcy.',
            );
        }

        if (! in_array($identity['type'] ?? null, ['pl_nip', 'eu_vat', 'none'], true)
            || ($flags['jst'] ?? null) !== false
            || ($flags['vat_group'] ?? null) !== false) {
            throw $this->error(
                'ksef_fa3_buyer_snapshot_unsupported',
                'Snapshot nabywcy zawiera semantykę nieobsługiwaną przez profil zwykły FA(3).',
            );
        }

        return $identity;
    }

    /** @param array<string, mixed> $buyer */
    private function addressLine(array $buyer): string
    {
        $street = $this->optionalString($buyer['street'] ?? null);
        $building = $this->optionalString($buyer['building_number'] ?? null);
        $apartment = $this->optionalString($buyer['apartment_number'] ?? null);
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
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function assertTaxSnapshot(Invoice $invoice): array
    {
        $snapshot = data_get($invoice->tax_metadata_snapshot, 'ksef_tax');
        if (! is_array($snapshot)) {
            throw $this->error(
                'ksef_fa3_tax_snapshot_missing',
                'Faktura nie posiada snapshotu semantyki podatkowej KSeF.',
            );
        }

        if (($snapshot['version'] ?? null) !== 1) {
            throw $this->error(
                'ksef_fa3_snapshot_version_unsupported',
                'Wersja snapshotu semantyki KSeF nie jest obsługiwana.',
            );
        }

        if (($snapshot['profile'] ?? null) !== 'ordinary'
            || ! $this->hasOrdinaryAnnotations($snapshot['annotations'] ?? null)
            || ! is_array($snapshot['line_treatments'] ?? null)) {
            throw $this->error(
                'ksef_fa3_tax_snapshot_invalid',
                'Snapshot semantyki podatkowej KSeF jest niekompletny.',
            );
        }

        $treatments = collect($snapshot['line_treatments'])
            ->filter(fn (mixed $treatment): bool => is_array($treatment))
            ->keyBy(fn (array $treatment): int => (int) ($treatment['invoice_item_id'] ?? 0));
        $items = $invoice->items()->orderBy('position')->orderBy('id')->get();

        if ($items->isEmpty()) {
            throw $this->error(
                'ksef_fa3_items_missing',
                'Faktura nie zawiera żadnych pozycji.',
                ['item_count' => 0],
            );
        }

        if ($treatments->count() !== $items->count()) {
            /** @var InvoiceItem|null $missingItem */
            $missingItem = $items->first(
                fn (InvoiceItem $item): bool => ! $treatments->has($item->getKey()),
            );

            throw $this->error(
                'ksef_fa3_tax_snapshot_invalid',
                'Snapshot semantyki podatkowej KSeF nie odpowiada pozycjom Faktury.',
                $missingItem !== null
                    ? $this->itemMetadata($missingItem, 'tax_treatment_missing')
                    : [
                        'reason' => 'tax_treatment_count_mismatch',
                        'item_count' => $items->count(),
                        'treatment_count' => $treatments->count(),
                    ],
            );
        }

        foreach ($items as $item) {
            $treatment = $treatments->get($item->getKey());
            if (! is_array($treatment)) {
                throw $this->error(
                    'ksef_fa3_tax_snapshot_invalid',
                    'Snapshot semantyki podatkowej KSeF nie odpowiada pozycjom Faktury.',
                    $this->itemMetadata($item, 'tax_treatment_missing'),
                );
            }

            if (! $this->taxTreatments->isCanonical($item, $treatment)) {
                throw $this->error(
                    'ksef_fa3_tax_snapshot_invalid',
                    'Snapshot semantyki podatkowej KSeF nie odpowiada pozycjom Faktury.',
                    $this->itemMetadata($item, 'tax_treatment_inconsistent'),
                );
            }

            if (($treatment['status'] ?? null) !== 'resolved') {
                $reason = ($treatment['reason'] ?? null) === 'unsupported_vat_code'
                    ? 'unsupported_vat_code'
                    : 'unsupported_vat_rate';
                $errorCode = $reason === 'unsupported_vat_code'
                    ? 'ksef_fa3_unsupported_vat_code'
                    : 'ksef_fa3_unsupported_vat_rate';

                throw $this->error(
                    $errorCode,
                    'Faktura zawiera stawkę lub kod VAT nieobsługiwany przez aktualny profil FA(3).',
                    $this->itemMetadata($item, $reason, $treatment),
                );
            }
        }

        return $treatments->values()->all();
    }

    private function hasOrdinaryAnnotations(mixed $annotations): bool
    {
        return is_array($annotations)
            && ($annotations['cash_accounting'] ?? null) === false
            && ($annotations['self_billing'] ?? null) === false
            && ($annotations['reverse_charge'] ?? null) === false
            && is_bool($annotations['split_payment'] ?? null)
            && array_key_exists('exemption', $annotations)
            && $annotations['exemption'] === null
            && ($annotations['new_transport_mean'] ?? null) === false
            && ($annotations['triangular_transaction'] ?? null) === false
            && ($annotations['margin_scheme'] ?? null) === false;
    }

    /**
     * @param  array<string, mixed>  $treatment
     * @return array<string, mixed>
     */
    private function itemMetadata(
        InvoiceItem $item,
        string $reason,
        array $treatment = [],
    ): array {
        $vatRate = $treatment['vat_rate'] ?? $item->vat_rate;
        $vatCode = $treatment['vat_code'] ?? $item->vat_code;

        return array_filter([
            'invoice_item' => [
                'id' => $item->getKey(),
                'position' => $item->position,
                'name' => (string) $item->name,
            ],
            'reason' => $reason,
            'vat_rate' => is_string($vatRate) && trim($vatRate) !== '' ? trim($vatRate) : null,
            'vat_code' => is_string($vatCode) && trim($vatCode) !== '' ? trim($vatCode) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $metadata */
    private function error(string $code, string $message, array $metadata = []): InvoiceDomainException
    {
        return new InvoiceDomainException($code, $message, $metadata);
    }
}
