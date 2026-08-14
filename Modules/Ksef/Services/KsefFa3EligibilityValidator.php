<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Models\KsefSetting;

class KsefFa3EligibilityValidator
{
    public function __construct(
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
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
    }

    /** @param array<string, mixed> $seller */
    private function assertSeller(array $seller): void
    {
        $name = trim((string) ($seller['name'] ?? ''));
        $country = strtoupper(trim((string) ($seller['country_code'] ?? '')));
        $nip = $this->buyerIdentity->normalizePolishNip($seller['tax_id'] ?? null);
        $addressLine = trim(implode(' ', array_filter([
            $seller['street'] ?? null,
            $seller['building_number'] ?? null,
            $seller['apartment_number'] ?? null,
            $seller['postal_code'] ?? null,
            $seller['city'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        if ($name === '' || $country !== 'PL' || $nip === null || $addressLine === '') {
            throw $this->error(
                'ksef_fa3_seller_incomplete',
                'Snapshot sprzedawcy nie zawiera kompletnych danych wymaganych dla FA(3).',
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

        if ($treatments->count() !== $items->count()) {
            throw $this->error(
                'ksef_fa3_tax_snapshot_invalid',
                'Snapshot semantyki podatkowej KSeF nie odpowiada pozycjom Faktury.',
            );
        }

        foreach ($items as $item) {
            $treatment = $treatments->get($item->getKey());
            if (! is_array($treatment)
                || (int) ($treatment['position'] ?? 0) !== $item->position
                || ($treatment['tax_identity'] ?? null) !== $this->taxIdentity->key(
                    $this->taxIdentity->normalize($item->vat_rate, $item->vat_code),
                )) {
                throw $this->error(
                    'ksef_fa3_tax_snapshot_invalid',
                    'Snapshot semantyki podatkowej KSeF nie odpowiada pozycjom Faktury.',
                );
            }

            if (($treatment['status'] ?? null) !== 'resolved') {
                $reason = ($treatment['reason'] ?? null) === 'unsupported_vat_code'
                    ? 'ksef_fa3_unsupported_vat_code'
                    : 'ksef_fa3_unsupported_vat_rate';

                throw $this->error(
                    $reason,
                    'Faktura zawiera stawkę lub kod VAT nieobsługiwany przez aktualny profil FA(3).',
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

    private function error(string $code, string $message): InvoiceDomainException
    {
        return new InvoiceDomainException($code, $message);
    }
}
