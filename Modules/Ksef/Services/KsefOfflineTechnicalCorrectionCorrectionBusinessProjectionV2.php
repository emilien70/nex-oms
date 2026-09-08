<?php

namespace Modules\Ksef\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineIssuance;

final class KsefOfflineTechnicalCorrectionCorrectionBusinessProjectionV2
{
    private const FA3_NAMESPACE = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    private const BUCKET_FIELDS = [
        'standard_1' => ['net' => 'P_13_1', 'vat' => 'P_14_1', 'pln_vat' => 'P_14_1W'],
        'standard_2' => ['net' => 'P_13_2', 'vat' => 'P_14_2', 'pln_vat' => 'P_14_2W'],
        'standard_3' => ['net' => 'P_13_3', 'vat' => 'P_14_3', 'pln_vat' => 'P_14_3W'],
        'domestic_zero' => ['net' => 'P_13_6_1'],
        'wdt' => ['net' => 'P_13_6_2'],
        'export' => ['net' => 'P_13_6_3'],
    ];

    /** @return array<string, mixed> */
    public function projectInvoice(Invoice $correction, ?KsefEnvironment $environment = null): array
    {
        if (! $correction->isCorrection() || ! $correction->isIssued() || ! $correction->isFinalized()) {
            throw $this->invalidProjection();
        }

        $correction->loadMissing(['items', 'correctedInvoice']);
        $root = $correction->correctedInvoice;
        $metadata = $correction->tax_metadata_snapshot;
        $kor = is_array($metadata) ? data_get($metadata, 'ksef_correction') : null;
        if (! $root instanceof Invoice
            || ! $root->isInvoice()
            || ! is_array($kor)
            || ($kor['version'] ?? null) !== 1
            || ($kor['profile'] ?? null) !== 'correction'
            || ! is_array($kor['line_treatments'] ?? null)) {
            throw $this->invalidProjection();
        }

        [$lines, $taxBuckets, $hasWdt] = $this->invoiceLinesAndTaxBuckets(
            $correction,
            $kor['line_treatments'],
        );
        $buyerBeforeSnapshot = data_get($correction->order_snapshot, 'correction.buyer_before');
        $buyerAfterSnapshot = $correction->buyer_snapshot;
        $buyerBeforeSemantics = $kor['buyer_before_semantics'] ?? null;
        if (! is_array($buyerBeforeSnapshot) || ! is_array($buyerAfterSnapshot)) {
            throw $this->invalidProjection();
        }

        $buyerChanged = $buyerBeforeSemantics !== null;
        if ($buyerChanged && ! is_array($buyerBeforeSemantics)) {
            throw $this->invalidProjection();
        }
        if ($lines === [] && ! $buyerChanged) {
            throw $this->invalidProjection();
        }

        $issueDate = $correction->issue_date?->toDateString() ?? '';
        $saleDate = $correction->sale_date?->toDateString();
        if ($saleDate === $issueDate) {
            $saleDate = null;
        }

        return [
            'header' => [
                'form_code' => 'FA',
                'system_code' => 'FA (3)',
                'schema_version' => '1-0E',
                'variant' => '3',
                'system_info' => 'NEX-OMS',
            ],
            'document_kind' => 'KOR',
            'seller' => $this->invoiceSeller(
                $correction->seller_snapshot,
                $this->sellerVatPrefixOption($root) ?? $hasWdt,
            ),
            'buyer_after' => $this->invoiceBuyer(
                $buyerAfterSnapshot,
                data_get($buyerAfterSnapshot, 'tax_identity'),
                data_get($buyerAfterSnapshot, 'subject_flags'),
            ),
            'buyer_before' => $buyerChanged
                ? $this->invoiceBuyer(
                    $buyerBeforeSnapshot,
                    data_get($buyerBeforeSemantics, 'tax_identity'),
                    data_get($buyerBeforeSemantics, 'subject_flags'),
                    includeFlags: false,
                )
                : null,
            'buyer_link_id' => $buyerChanged ? 'NB/01' : null,
            'invoice' => [
                'currency' => $this->currency($correction->currency),
                'issue_date' => $this->date($issueDate),
                'place_of_issue' => $this->optionalString(data_get($correction->issuer_snapshot, 'place_of_issue')),
                'number' => $this->requiredString($correction->number),
                'sale_date' => $saleDate === null ? null : $this->date($saleDate),
                'tax_buckets' => $taxBuckets,
                'total_gross' => $this->money($correction->total_gross),
                'annotations' => $this->invoiceAnnotations($root),
            ],
            'reason' => $this->requiredString($correction->correction_reason),
            'source_reference' => $this->invoiceSourceReference(
                $correction,
                $root,
                $environment ?? $this->issuanceEnvironment($correction),
            ),
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    public function projectPayload(string $xml): array
    {
        $xpath = $this->xpath($xml);
        $root = $this->requiredNode($xpath, '/fa:Faktura');
        $header = $this->requiredNode($xpath, './fa:Naglowek', $root);
        $seller = $this->requiredNode($xpath, './fa:Podmiot1', $root);
        $buyer = $this->requiredNode($xpath, './fa:Podmiot2', $root);
        $invoice = $this->requiredNode($xpath, './fa:Fa', $root);
        $this->assertChildren($root, ['Naglowek', 'Podmiot1', 'Podmiot2', 'Fa']);

        $formCode = $this->requiredNode($xpath, './fa:KodFormularza', $header);
        $this->assertChildren($header, [
            'KodFormularza',
            'WariantFormularza',
            'DataWytworzeniaFa',
            'SystemInfo',
        ]);
        if ($formCode->attributes->length !== 2) {
            throw $this->invalidProjection();
        }

        $buyerBefore = $this->optionalNode($xpath, './fa:Podmiot2K', $invoice);
        $buyerLinkId = $this->optionalText($xpath, './fa:IDNabywcy', $buyer);
        if (($buyerBefore === null) !== ($buyerLinkId === null)) {
            throw $this->invalidProjection();
        }
        if ($buyerBefore !== null
            && $this->requiredText($xpath, './fa:IDNabywcy', $buyerBefore) !== $buyerLinkId) {
            throw $this->invalidProjection();
        }

        $allowedInvoiceChildren = [
            'KodWaluty', 'P_1', 'P_1M', 'P_2', 'P_6', 'P_15', 'Adnotacje',
            'RodzajFaktury', 'PrzyczynaKorekty', 'DaneFaKorygowanej', 'Podmiot2K', 'FaWiersz',
        ];
        foreach (self::BUCKET_FIELDS as $fields) {
            array_push($allowedInvoiceChildren, ...array_values($fields));
        }
        $this->assertChildren($invoice, $allowedInvoiceChildren);

        return [
            'header' => [
                'form_code' => $this->nodeText($formCode),
                'system_code' => $this->requiredAttribute($formCode, 'kodSystemowy'),
                'schema_version' => $this->requiredAttribute($formCode, 'wersjaSchemy'),
                'variant' => $this->requiredText($xpath, './fa:WariantFormularza', $header),
                'system_info' => $this->requiredText($xpath, './fa:SystemInfo', $header),
            ],
            'document_kind' => $this->requiredText($xpath, './fa:RodzajFaktury', $invoice),
            'seller' => $this->payloadSeller($xpath, $seller),
            'buyer_after' => $this->payloadBuyer($xpath, $buyer, includeFlags: true),
            'buyer_before' => $buyerBefore === null
                ? null
                : $this->payloadBuyer($xpath, $buyerBefore, includeFlags: false),
            'buyer_link_id' => $buyerLinkId,
            'invoice' => [
                'currency' => $this->currency($this->requiredText($xpath, './fa:KodWaluty', $invoice)),
                'issue_date' => $this->date($this->requiredText($xpath, './fa:P_1', $invoice)),
                'place_of_issue' => $this->optionalText($xpath, './fa:P_1M', $invoice),
                'number' => $this->requiredText($xpath, './fa:P_2', $invoice),
                'sale_date' => ($date = $this->optionalText($xpath, './fa:P_6', $invoice)) === null
                    ? null
                    : $this->date($date),
                'tax_buckets' => $this->payloadTaxBuckets($xpath, $invoice),
                'total_gross' => $this->money($this->requiredText($xpath, './fa:P_15', $invoice)),
                'annotations' => $this->payloadAnnotations($xpath, $invoice),
            ],
            'reason' => $this->requiredText($xpath, './fa:PrzyczynaKorekty', $invoice),
            'source_reference' => $this->payloadSourceReference($xpath, $invoice),
            'lines' => $this->payloadLines($xpath, $invoice),
        ];
    }

    /**
     * @param  list<mixed>  $treatments
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: bool}
     */
    private function invoiceLinesAndTaxBuckets(Invoice $correction, array $treatments): array
    {
        $items = $correction->items()->orderBy('position')->orderBy('id')->get();
        $itemsById = $items->keyBy(fn ($item): int => (int) $item->getKey());
        $treatmentsByItem = [];
        $hasWdt = false;
        foreach ($treatments as $treatment) {
            $itemId = is_array($treatment) ? ($treatment['invoice_item_id'] ?? null) : null;
            if (! is_int($itemId) || isset($treatmentsByItem[$itemId])) {
                throw $this->invalidProjection();
            }
            $treatmentsByItem[$itemId] = $treatment;
            $hasWdt = $hasWdt
                || data_get($treatment, 'before.treatment') === 'wdt'
                || data_get($treatment, 'after.treatment') === 'wdt';
        }
        if (count($treatmentsByItem) !== $items->count()) {
            throw $this->invalidProjection();
        }

        $buckets = array_fill_keys(array_keys(self::BUCKET_FIELDS), null);
        $lines = [];
        foreach ($itemsById as $itemId => $item) {
            $before = $item->correction_before_snapshot;
            $after = $item->correction_after_snapshot;
            $treatment = $treatmentsByItem[$itemId] ?? null;
            if (! is_array($before)
                || ! is_array($after)
                || ! is_array($treatment)
                || ($treatment['position'] ?? null) !== $item->position
                || ! is_array($treatment['before'] ?? null)
                || ! is_array($treatment['after'] ?? null)) {
                throw $this->invalidProjection();
            }

            if ($this->comparableLine($before) === $this->comparableLine($after)) {
                continue;
            }

            $beforeLine = $this->invoiceLine($before, $treatment['before']);
            $afterLine = $this->invoiceLine($after, $treatment['after']);
            $position = $this->positiveInteger($after['position'] ?? null);
            if ($position !== $item->position) {
                throw $this->invalidProjection();
            }
            $lines[] = [
                'position' => $position,
                'before' => $beforeLine,
                'after' => $afterLine,
            ];
            $this->addLineToBucket($buckets, $treatment['before'], $before, subtract: true);
            $this->addLineToBucket($buckets, $treatment['after'], $after, subtract: false);
        }
        usort($lines, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);
        if ($this->currency($correction->currency) !== 'PLN'
            && $this->isMonetaryCorrection($correction)) {
            $this->addConvertedVat($correction, $buckets);
        }
        foreach ($buckets as $key => $bucket) {
            if (is_array($bucket)
                && $this->compare($bucket['net'], '0.00') === 0
                && $this->compare($bucket['vat'] ?? '0.00', '0.00') === 0
                && $this->compare($bucket['pln_vat'] ?? '0.00', '0.00') === 0) {
                $buckets[$key] = null;
            } elseif (is_array($bucket) && ! str_starts_with($key, 'standard_')) {
                $buckets[$key] = ['net' => $bucket['net']];
            }
        }
        foreach (['standard_1', 'standard_2', 'standard_3'] as $key) {
            if (is_array($buckets[$key])) {
                $buckets[$key] += ['pln_vat' => null];
            }
        }

        return [$lines, $buckets, $hasWdt];
    }

    /** @param array<string, mixed> $snapshot
     * @param  array<string, mixed>  $treatment
     * @return array<string, mixed>
     */
    private function invoiceLine(array $snapshot, array $treatment): array
    {
        $fa3Rate = $this->fa3Rate($treatment['fa3_rate'] ?? null);
        $expectedTreatment = match ($this->bucketForRate($fa3Rate)) {
            'standard_1', 'standard_2', 'standard_3' => 'standard',
            default => $this->bucketForRate($fa3Rate),
        };
        if (($treatment['status'] ?? null) !== 'resolved'
            || ($treatment['treatment'] ?? null) !== $expectedTreatment) {
            throw $this->invalidProjection();
        }

        return [
            'name' => $this->requiredString($snapshot['name'] ?? null),
            'unit_name' => $this->requiredString($snapshot['unit_name'] ?? null),
            'quantity' => $this->quantity($snapshot['quantity'] ?? null),
            'unit_price_net' => $this->money($snapshot['unit_price_net'] ?? null),
            'total_net' => $this->money($snapshot['total_net'] ?? null),
            'fa3_rate' => $fa3Rate,
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function comparableLine(array $snapshot): array
    {
        $result = [];
        foreach (['line_type', 'name', 'description', 'unit_name'] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                throw $this->invalidProjection();
            }
            $result[$field] = $this->optionalString($snapshot[$field]);
        }
        foreach ([
            'quantity' => 4,
            'unit_price_net' => 4,
            'unit_price_gross' => 4,
            'total_net' => 2,
            'total_vat' => 2,
            'total_gross' => 2,
        ] as $field => $scale) {
            if (! array_key_exists($field, $snapshot)) {
                throw $this->invalidProjection();
            }
            $result[$field] = $this->decimal($snapshot[$field], $scale, false);
        }
        $result['vat_rate'] = array_key_exists('vat_rate', $snapshot) && $snapshot['vat_rate'] !== null
            ? $this->decimal($snapshot['vat_rate'], 2, false)
            : null;
        $result['vat_code'] = array_key_exists('vat_code', $snapshot)
            ? ($this->optionalString($snapshot['vat_code']) === null
                ? null
                : strtoupper($this->optionalString($snapshot['vat_code'])))
            : throw $this->invalidProjection();

        return $result;
    }

    /** @param array<string, mixed> $buckets
     * @param  array<string, mixed>  $treatment
     * @param  array<string, mixed>  $line
     */
    private function addLineToBucket(array &$buckets, array $treatment, array $line, bool $subtract): void
    {
        $bucket = $this->bucketForRate($this->fa3Rate($treatment['fa3_rate'] ?? null));
        $buckets[$bucket] ??= ['net' => '0.00', 'vat' => '0.00'];
        foreach (['net' => 'total_net', 'vat' => 'total_vat'] as $amount => $field) {
            $value = $this->money($line[$field] ?? null);
            $buckets[$bucket][$amount] = $subtract
                ? $this->subtract($buckets[$bucket][$amount], $value)
                : $this->add($buckets[$bucket][$amount], $value);
        }
    }

    /** @param array<string, mixed> $buckets */
    private function addConvertedVat(Invoice $correction, array &$buckets): void
    {
        $metadata = $correction->tax_metadata_snapshot;
        $conversion = is_array($metadata) ? ($metadata['currency_conversion'] ?? null) : null;
        $summary = is_array($metadata) ? ($metadata['converted_tax_summary'] ?? null) : null;
        $difference = data_get($correction->correction_totals_snapshot, 'difference.tax_summary_snapshot');
        if (! is_array($conversion)
            || ! is_array($summary)
            || ! is_array($difference)
            || ($conversion['version'] ?? null) !== 1
            || strtoupper((string) ($conversion['source_currency'] ?? '')) !== $this->currency($correction->currency)
            || ($conversion['target_currency'] ?? null) !== 'PLN'
            || ($conversion['rounding_mode'] ?? null) !== 'half_up'
            || ($conversion['result_scale'] ?? null) !== 2
            || ($summary['currency'] ?? null) !== 'PLN'
            || ! is_array($summary['groups'] ?? null)) {
            throw $this->invalidProjection();
        }

        $converted = [];
        foreach ($summary['groups'] as $group) {
            if (! is_array($group)) {
                throw $this->invalidProjection();
            }
            $identity = $this->taxIdentity($group['vat_rate'] ?? null, $group['vat_code'] ?? null);
            if (isset($converted[$identity])) {
                throw $this->invalidProjection();
            }
            $converted[$identity] = $this->money($group['vat'] ?? null);
        }
        $expected = [];
        foreach ($difference as $group) {
            if (! is_array($group)) {
                throw $this->invalidProjection();
            }
            $expected[] = $this->taxIdentity($group['vat_rate'] ?? null, $group['vat_code'] ?? null);
        }
        sort($expected, SORT_STRING);
        $actual = array_keys($converted);
        sort($actual, SORT_STRING);
        if (array_values(array_unique($expected)) !== $actual) {
            throw $this->invalidProjection();
        }

        foreach ([
            'standard_1' => ['rate:23.00', 'rate:22.00'],
            'standard_2' => ['rate:8.00', 'rate:7.00'],
            'standard_3' => ['rate:5.00'],
        ] as $bucket => $identities) {
            if (! is_array($buckets[$bucket])) {
                continue;
            }
            $plnVat = '0.00';
            foreach ($identities as $identity) {
                $plnVat = $this->add($plnVat, $converted[$identity] ?? '0.00');
            }
            $buckets[$bucket]['pln_vat'] = $plnVat;
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function invoiceSeller(array $snapshot, bool $includeVatPrefix): array
    {
        return [
            'taxpayer_prefix' => $includeVatPrefix ? 'PL' : null,
            'nip' => $this->polishNip($snapshot['tax_id'] ?? null),
            'name' => $this->requiredString($snapshot['name'] ?? null),
            'address' => $this->invoiceAddress($snapshot),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function invoiceBuyer(
        array $snapshot,
        mixed $identity,
        mixed $flags,
        bool $includeFlags = true,
    ): array {
        if (! is_array($identity)
            || ($identity['version'] ?? null) !== 1
            || ($identity['status'] ?? null) !== 'resolved'
            || ! in_array($identity['type'] ?? null, ['pl_nip', 'eu_vat', 'none'], true)) {
            throw $this->invalidProjection();
        }
        if ($includeFlags
            && (! is_array($flags)
                || ($flags['version'] ?? null) !== 1
                || ! is_bool($flags['jst'] ?? null)
                || ! is_bool($flags['vat_group'] ?? null))) {
            throw $this->invalidProjection();
        }

        return [
            'identity_type' => $identity['type'],
            'identity_country_code' => $this->optionalString($identity['country_code'] ?? null),
            'identity_identifier' => $this->optionalString($identity['identifier'] ?? null),
            'name' => $this->optionalString($snapshot['company_name'] ?? null)
                ?? $this->optionalString($snapshot['name'] ?? null),
            'address' => $this->invoiceAddress($snapshot),
            'jst' => $includeFlags ? $flags['jst'] : null,
            'vat_group' => $includeFlags ? $flags['vat_group'] : null,
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, ?string>
     */
    private function invoiceAddress(array $snapshot): array
    {
        $street = $this->optionalString($snapshot['street'] ?? null);
        $building = $this->optionalString($snapshot['building_number'] ?? null);
        $apartment = $this->optionalString($snapshot['apartment_number'] ?? null);
        $number = $building;
        if ($apartment !== null) {
            $number = $number === null ? $apartment : $number.'/'.$apartment;
        }
        $line1 = trim(implode(' ', array_filter([$street, $number])));
        $line2 = trim(implode(' ', array_filter([
            $this->optionalString($snapshot['postal_code'] ?? null),
            $this->optionalString($snapshot['city'] ?? null),
        ])));
        if ($line1 === '') {
            throw $this->invalidProjection();
        }

        return [
            'country_code' => $this->countryCode($snapshot['country_code'] ?? null),
            'line_1' => $line1,
            'line_2' => $line2 === '' ? null : $line2,
        ];
    }

    /** @return array<string, bool> */
    private function invoiceAnnotations(Invoice $root): array
    {
        $snapshot = data_get($root->tax_metadata_snapshot, 'ksef_tax');
        $annotations = is_array($snapshot) ? ($snapshot['annotations'] ?? null) : null;
        if (! is_array($snapshot)
            || ($snapshot['version'] ?? null) !== 1
            || ($snapshot['profile'] ?? null) !== 'ordinary'
            || ! is_array($annotations)
            || ($annotations['cash_accounting'] ?? null) !== false
            || ($annotations['self_billing'] ?? null) !== false
            || ($annotations['reverse_charge'] ?? null) !== false
            || ! is_bool($annotations['split_payment'] ?? null)
            || ($annotations['exemption'] ?? null) !== null
            || ($annotations['new_transport_mean'] ?? null) !== false
            || ($annotations['triangular_transaction'] ?? null) !== false
            || ($annotations['margin_scheme'] ?? null) !== false) {
            throw $this->invalidProjection();
        }

        return [
            'cash_accounting' => false,
            'self_billing' => false,
            'reverse_charge' => false,
            'split_payment' => $annotations['split_payment'],
            'new_transport_mean' => false,
            'triangular_transaction' => false,
            'margin_scheme' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceSourceReference(
        Invoice $correction,
        Invoice $root,
        KsefEnvironment $environment,
    ): array {
        $source = data_get($correction->correction_totals_snapshot, 'source_invoice');
        if (! is_array($source)
            || ($source['invoice_id'] ?? null) !== $correction->corrected_invoice_id
            || $root->getKey() !== $correction->corrected_invoice_id) {
            throw $this->invalidProjection();
        }
        $base = [
            'number' => $this->requiredString($source['number'] ?? null),
            'issue_date' => $this->date($this->requiredString($source['issue_date'] ?? null)),
        ];
        $provenances = KsefInvoiceProvenance::query()
            ->where('invoice_id', $root->getKey())
            ->where('environment', $environment->value)
            ->get();
        $submissions = KsefInvoiceSubmission::query()
            ->where('invoice_id', $root->getKey())
            ->where('environment', $environment->value)
            ->get();

        if ($provenances->isNotEmpty()) {
            if ($provenances->count() !== 1
                || $provenances->first()->provenance !== KsefInvoiceProvenanceType::OutsideKsef
                || $submissions->isNotEmpty()
                || KsefOfflineIssuance::query()
                    ->where('invoice_id', $root->getKey())
                    ->where('environment', $environment->value)
                    ->exists()) {
                throw $this->invalidProjection();
            }

            return ['type' => 'outside_ksef', 'ksef_number' => null, ...$base];
        }

        $accepted = $submissions->filter(
            static fn (KsefInvoiceSubmission $submission): bool => $submission->status === KsefInvoiceSubmissionStatus::Accepted,
        )->values();
        if ($accepted->count() !== 1) {
            throw $this->invalidProjection();
        }
        $submission = $accepted->first();
        $ksefNumber = $this->requiredString($submission->ksef_number);
        if (! hash_equals($this->polishNip(data_get($root->seller_snapshot, 'tax_id')), (string) $submission->seller_nip)
            || ! str_starts_with($ksefNumber, (string) $submission->seller_nip.'-')) {
            throw $this->invalidProjection();
        }

        return ['type' => 'ksef', 'ksef_number' => $ksefNumber, ...$base];
    }

    private function issuanceEnvironment(Invoice $correction): KsefEnvironment
    {
        $environments = KsefOfflineIssuance::query()
            ->where('invoice_id', $correction->getKey())
            ->get(['environment'])
            ->map(static fn (KsefOfflineIssuance $issuance): string => $issuance->environment->value)
            ->uniqueStrict()
            ->values();
        if ($environments->count() !== 1) {
            throw $this->invalidProjection();
        }

        return KsefEnvironment::from($environments->first());
    }

    private function sellerVatPrefixOption(Invoice $root): ?bool
    {
        $snapshot = data_get($root->tax_metadata_snapshot, 'ksef_document');
        if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 2) {
            return null;
        }
        $value = data_get($snapshot, 'options.include_seller_vat_prefix');

        return is_bool($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function payloadSeller(DOMXPath $xpath, DOMNode $seller): array
    {
        $this->assertChildren($seller, ['PrefiksPodatnika', 'DaneIdentyfikacyjne', 'Adres']);
        $identity = $this->requiredNode($xpath, './fa:DaneIdentyfikacyjne', $seller);
        $this->assertChildren($identity, ['NIP', 'Nazwa']);

        return [
            'taxpayer_prefix' => $this->optionalText($xpath, './fa:PrefiksPodatnika', $seller),
            'nip' => $this->polishNip($this->requiredText($xpath, './fa:NIP', $identity)),
            'name' => $this->requiredText($xpath, './fa:Nazwa', $identity),
            'address' => $this->payloadAddress($xpath, $this->requiredNode($xpath, './fa:Adres', $seller)),
        ];
    }

    /** @return array<string, mixed> */
    private function payloadBuyer(DOMXPath $xpath, DOMNode $buyer, bool $includeFlags): array
    {
        $allowed = ['DaneIdentyfikacyjne', 'Adres', 'IDNabywcy'];
        if ($includeFlags) {
            array_push($allowed, 'JST', 'GV');
        }
        $this->assertChildren($buyer, $allowed);
        $identity = $this->requiredNode($xpath, './fa:DaneIdentyfikacyjne', $buyer);
        $nip = $this->optionalText($xpath, './fa:NIP', $identity);
        $country = $this->optionalText($xpath, './fa:KodUE', $identity);
        $euVat = $this->optionalText($xpath, './fa:NrVatUE', $identity);
        $noId = $this->optionalText($xpath, './fa:BrakID', $identity);
        $name = $this->optionalText($xpath, './fa:Nazwa', $identity);

        if ($nip !== null && $country === null && $euVat === null && $noId === null) {
            $this->assertChildren($identity, ['NIP', 'Nazwa']);
            $type = 'pl_nip';
            $identifier = $nip;
            $country = 'PL';
        } elseif ($nip === null && $country !== null && $euVat !== null && $noId === null) {
            $this->assertChildren($identity, ['KodUE', 'NrVatUE', 'Nazwa']);
            $type = 'eu_vat';
            $identifier = $euVat;
        } elseif ($nip === null && $country === null && $euVat === null && $noId === '1') {
            $this->assertChildren($identity, ['BrakID', 'Nazwa']);
            $type = 'none';
            $identifier = null;
            $country = null;
        } else {
            throw $this->invalidProjection();
        }

        return [
            'identity_type' => $type,
            'identity_country_code' => $country,
            'identity_identifier' => $identifier,
            'name' => $name,
            'address' => $this->payloadAddress($xpath, $this->requiredNode($xpath, './fa:Adres', $buyer)),
            'jst' => $includeFlags ? $this->indicator($this->requiredText($xpath, './fa:JST', $buyer)) : null,
            'vat_group' => $includeFlags ? $this->indicator($this->requiredText($xpath, './fa:GV', $buyer)) : null,
        ];
    }

    /** @return array<string, ?string> */
    private function payloadAddress(DOMXPath $xpath, DOMNode $address): array
    {
        $this->assertChildren($address, ['KodKraju', 'AdresL1', 'AdresL2']);

        return [
            'country_code' => $this->countryCode($this->requiredText($xpath, './fa:KodKraju', $address)),
            'line_1' => $this->requiredText($xpath, './fa:AdresL1', $address),
            'line_2' => $this->optionalText($xpath, './fa:AdresL2', $address),
        ];
    }

    /** @return array<string, mixed> */
    private function payloadTaxBuckets(DOMXPath $xpath, DOMNode $invoice): array
    {
        $buckets = [];
        foreach (self::BUCKET_FIELDS as $bucket => $fields) {
            $values = [];
            foreach ($fields as $amount => $element) {
                $value = $this->optionalText($xpath, './fa:'.$element, $invoice);
                $values[$amount] = $value === null ? null : $this->money($value);
            }
            if (str_starts_with($bucket, 'standard_')) {
                if (($values['net'] === null) !== ($values['vat'] === null)
                    || ($values['net'] === null && $values['pln_vat'] !== null)) {
                    throw $this->invalidProjection();
                }
                $buckets[$bucket] = $values['net'] === null ? null : $values;
            } else {
                $buckets[$bucket] = $values['net'] === null ? null : ['net' => $values['net']];
            }
        }

        return $buckets;
    }

    /** @return array<string, bool> */
    private function payloadAnnotations(DOMXPath $xpath, DOMNode $invoice): array
    {
        $annotations = $this->requiredNode($xpath, './fa:Adnotacje', $invoice);
        $this->assertChildren($annotations, [
            'P_16', 'P_17', 'P_18', 'P_18A', 'Zwolnienie', 'NoweSrodkiTransportu', 'P_23', 'PMarzy',
        ]);
        $exemption = $this->requiredNode($xpath, './fa:Zwolnienie', $annotations);
        $transport = $this->requiredNode($xpath, './fa:NoweSrodkiTransportu', $annotations);
        $margin = $this->requiredNode($xpath, './fa:PMarzy', $annotations);
        $this->assertChildren($exemption, ['P_19N']);
        $this->assertChildren($transport, ['P_22N']);
        $this->assertChildren($margin, ['P_PMarzyN']);
        if ($this->requiredText($xpath, './fa:P_19N', $exemption) !== '1'
            || $this->requiredText($xpath, './fa:P_22N', $transport) !== '1'
            || $this->requiredText($xpath, './fa:P_PMarzyN', $margin) !== '1') {
            throw $this->invalidProjection();
        }

        return [
            'cash_accounting' => $this->indicator($this->requiredText($xpath, './fa:P_16', $annotations)),
            'self_billing' => $this->indicator($this->requiredText($xpath, './fa:P_17', $annotations)),
            'reverse_charge' => $this->indicator($this->requiredText($xpath, './fa:P_18', $annotations)),
            'split_payment' => $this->indicator($this->requiredText($xpath, './fa:P_18A', $annotations)),
            'new_transport_mean' => false,
            'triangular_transaction' => $this->indicator($this->requiredText($xpath, './fa:P_23', $annotations)),
            'margin_scheme' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function payloadSourceReference(DOMXPath $xpath, DOMNode $invoice): array
    {
        $source = $this->requiredNode($xpath, './fa:DaneFaKorygowanej', $invoice);
        $this->assertChildren($source, [
            'DataWystFaKorygowanej', 'NrFaKorygowanej', 'NrKSeF', 'NrKSeFN', 'NrKSeFFaKorygowanej',
        ]);
        $inKsef = $this->optionalText($xpath, './fa:NrKSeF', $source);
        $outside = $this->optionalText($xpath, './fa:NrKSeFN', $source);
        $number = $this->optionalText($xpath, './fa:NrKSeFFaKorygowanej', $source);
        if ($inKsef === '1' && $outside === null && $number !== null) {
            $type = 'ksef';
        } elseif ($outside === '1' && $inKsef === null && $number === null) {
            $type = 'outside_ksef';
        } else {
            throw $this->invalidProjection();
        }

        return [
            'type' => $type,
            'ksef_number' => $number,
            'number' => $this->requiredText($xpath, './fa:NrFaKorygowanej', $source),
            'issue_date' => $this->date($this->requiredText($xpath, './fa:DataWystFaKorygowanej', $source)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function payloadLines(DOMXPath $xpath, DOMNode $invoice): array
    {
        $pairs = [];
        foreach ($this->nodes($xpath, './fa:FaWiersz', $invoice) as $node) {
            if (! $node instanceof DOMElement) {
                throw $this->invalidProjection();
            }
            $this->assertChildren($node, [
                'NrWierszaFa', 'P_7', 'P_8A', 'P_8B', 'P_9A', 'P_11', 'P_12', 'StanPrzed',
            ]);
            $position = $this->positiveInteger($this->requiredText($xpath, './fa:NrWierszaFa', $node));
            $before = $this->optionalText($xpath, './fa:StanPrzed', $node);
            if ($before !== null && $before !== '1') {
                throw $this->invalidProjection();
            }
            $side = $before === '1' ? 'before' : 'after';
            if (isset($pairs[$position][$side])) {
                throw $this->invalidProjection();
            }
            $pairs[$position][$side] = [
                'name' => $this->requiredText($xpath, './fa:P_7', $node),
                'unit_name' => $this->requiredText($xpath, './fa:P_8A', $node),
                'quantity' => $this->quantity($this->requiredText($xpath, './fa:P_8B', $node)),
                'unit_price_net' => $this->money($this->requiredText($xpath, './fa:P_9A', $node)),
                'total_net' => $this->money($this->requiredText($xpath, './fa:P_11', $node)),
                'fa3_rate' => $this->fa3Rate($this->requiredText($xpath, './fa:P_12', $node)),
            ];
        }
        if ($pairs === []) {
            return [];
        }
        ksort($pairs, SORT_NUMERIC);
        $lines = [];
        foreach ($pairs as $position => $pair) {
            if (! isset($pair['before'], $pair['after'])) {
                throw $this->invalidProjection();
            }
            $lines[] = ['position' => (int) $position, ...$pair];
        }

        return $lines;
    }

    private function xpath(string $xml): DOMXPath
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $this->invalidProjection();
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded
            || $document->doctype !== null
            || $document->documentElement?->localName !== 'Faktura'
            || $document->documentElement?->namespaceURI !== self::FA3_NAMESPACE) {
            throw $this->invalidProjection();
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', self::FA3_NAMESPACE);

        return $xpath;
    }

    private function requiredNode(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMElement
    {
        $nodes = $this->nodes($xpath, $expression, $context);
        if ($nodes->length !== 1 || ! $nodes->item(0) instanceof DOMElement) {
            throw $this->invalidProjection();
        }

        return $nodes->item(0);
    }

    private function optionalNode(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMElement
    {
        $nodes = $this->nodes($xpath, $expression, $context);
        if ($nodes->length > 1 || ($nodes->length === 1 && ! $nodes->item(0) instanceof DOMElement)) {
            throw $this->invalidProjection();
        }

        return $nodes->length === 1 ? $nodes->item(0) : null;
    }

    private function requiredText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string
    {
        return $this->nodeText($this->requiredNode($xpath, $expression, $context));
    }

    private function optionalText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $node = $this->optionalNode($xpath, $expression, $context);

        return $node === null ? null : $this->nodeText($node);
    }

    private function nodeText(DOMNode $node): string
    {
        $value = trim($node->textContent);
        if ($value === '') {
            throw $this->invalidProjection();
        }

        return $value;
    }

    private function requiredAttribute(DOMElement $node, string $name): string
    {
        return $this->requiredString($node->getAttribute($name));
    }

    private function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): \DOMNodeList
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            throw $this->invalidProjection();
        }

        return $nodes;
    }

    /** @param list<string> $allowed */
    private function assertChildren(DOMNode $parent, array $allowed): void
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement
                || $child->namespaceURI !== self::FA3_NAMESPACE
                || ! in_array($child->localName, $allowed, true)) {
                throw $this->invalidProjection();
            }
        }
    }

    private function money(mixed $value): string
    {
        return $this->decimal($value, 2, false);
    }

    private function quantity(mixed $value): string
    {
        return $this->decimal($value, 4, true);
    }

    private function fa3Rate(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['0 KR', '0 WDT', '0 EX'], true)) {
            return $value;
        }

        return $this->decimal($value, 2, true);
    }

    private function decimal(mixed $value, int $scale, bool $trimZeros): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->invalidProjection();
        }
        $value = trim((string) $value);
        if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) !== 1) {
            throw $this->invalidProjection();
        }
        try {
            $normalized = (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);
        } catch (MathException|InvalidArgumentException) {
            throw $this->invalidProjection();
        }
        if ($trimZeros) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function add(string $left, string $right): string
    {
        try {
            return (string) BigDecimal::of($left)->plus($right)->toScale(2, RoundingMode::HALF_UP);
        } catch (MathException|InvalidArgumentException) {
            throw $this->invalidProjection();
        }
    }

    private function subtract(string $left, string $right): string
    {
        try {
            return (string) BigDecimal::of($left)->minus($right)->toScale(2, RoundingMode::HALF_UP);
        } catch (MathException|InvalidArgumentException) {
            throw $this->invalidProjection();
        }
    }

    private function compare(string $left, string $right): int
    {
        try {
            return BigDecimal::of($left)->compareTo($right);
        } catch (MathException|InvalidArgumentException) {
            throw $this->invalidProjection();
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->invalidProjection();
        }
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($filtered === false) {
            throw $this->invalidProjection();
        }

        return $filtered;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw $this->invalidProjection();
        }

        return $value;
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper($this->requiredString($value));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw $this->invalidProjection();
        }

        return $currency;
    }

    private function countryCode(mixed $value): string
    {
        $country = strtoupper($this->requiredString($value));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw $this->invalidProjection();
        }

        return $country;
    }

    private function indicator(string $value): bool
    {
        return match ($value) {
            '1' => true,
            '2' => false,
            default => throw $this->invalidProjection(),
        };
    }

    private function bucketForRate(string $rate): string
    {
        return match ($rate) {
            '23', '22' => 'standard_1',
            '8', '7' => 'standard_2',
            '5' => 'standard_3',
            '0 KR' => 'domestic_zero',
            '0 WDT' => 'wdt',
            '0 EX' => 'export',
            default => throw $this->invalidProjection(),
        };
    }

    private function taxIdentity(mixed $vatRate, mixed $vatCode): string
    {
        $code = $this->optionalString($vatCode);
        if ($code !== null) {
            return 'code:'.strtoupper($code);
        }
        if ($vatRate === null) {
            throw $this->invalidProjection();
        }

        return 'rate:'.$this->decimal($vatRate, 2, false);
    }

    private function polishNip(mixed $value): string
    {
        $nip = strtoupper($this->requiredString($value));
        $nip = preg_replace('/[\s.-]+/u', '', $nip);
        if (! is_string($nip)) {
            throw $this->invalidProjection();
        }
        if (str_starts_with($nip, 'PL')) {
            $nip = substr($nip, 2);
        }
        if (preg_match('/^\d{10}$/D', $nip) !== 1) {
            throw $this->invalidProjection();
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $checksum = 0;
        foreach ($weights as $position => $weight) {
            $checksum += ((int) $nip[$position]) * $weight;
        }
        if ($checksum % 11 === 10 || $checksum % 11 !== (int) $nip[9]) {
            throw $this->invalidProjection();
        }

        return $nip;
    }

    private function isMonetaryCorrection(Invoice $correction): bool
    {
        return $this->compare($this->money($correction->total_net), '0.00') !== 0
            || $this->compare($this->money($correction->total_vat), '0.00') !== 0
            || $this->compare($this->money($correction->total_gross), '0.00') !== 0;
    }

    private function requiredString(mixed $value): string
    {
        $value = $this->optionalString($value);
        if ($value === null) {
            throw $this->invalidProjection();
        }

        return $value;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function invalidProjection(): KsefApiException
    {
        return new KsefApiException(
            'Treść biznesowa korekty technicznej KOR jest niekompletna lub niespójna.',
            'ksef_technical_correction_business_projection_invalid',
        );
    }
}
