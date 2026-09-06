<?php

namespace Modules\Ksef\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;

final class KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1
{
    private const BUCKETS = [
        'standard_1',
        'standard_2',
        'standard_3',
        'domestic_zero',
        'wdt',
        'export',
    ];

    private const VERSION_1_OPTION_KEYS = [
        'include_recipient_data',
        'include_buyer_contact_data',
        'include_additional_information',
        'include_order_reference',
        'include_bank_account',
        'include_gtu',
    ];

    private const VERSION_2_OPTION_KEYS = [
        ...self::VERSION_1_OPTION_KEYS,
        'include_seller_vat_prefix',
    ];

    private const GTU_CODES = [
        'GTU_01', 'GTU_02', 'GTU_03', 'GTU_04', 'GTU_05', 'GTU_06', 'GTU_07',
        'GTU_08', 'GTU_09', 'GTU_10', 'GTU_11', 'GTU_12', 'GTU_13',
    ];

    private const LEGACY_PAYMENT_METHODS = [
        'cash' => '1',
        'gotowka' => '1',
        'gotówka' => '1',
        'card' => '2',
        'karta' => '2',
        'bon' => '3',
        'czek' => '4',
        'kredyt' => '5',
        'bank_transfer' => '6',
        'przelew' => '6',
        'transfer' => '6',
        'mobile' => '7',
        'mobilna' => '7',
    ];

    private const PAYMENT_TYPE_CODES = [
        'cash' => '1',
        'card' => '2',
        'voucher' => '3',
        'cheque' => '4',
        'credit' => '5',
        'transfer' => '6',
        'mobile' => '7',
    ];

    /** @return array<string, mixed> */
    public function project(Invoice $invoice): array
    {
        $items = $invoice->items()->orderBy('position')->orderBy('id')->get();
        $options = $this->documentOptions($invoice);
        $ksefTax = data_get($invoice->tax_metadata_snapshot, 'ksef_tax');
        $treatments = collect(is_array($ksefTax) ? ($ksefTax['line_treatments'] ?? []) : [])
            ->filter(static fn (mixed $value): bool => is_array($value))
            ->keyBy(static fn (array $value): int => (int) ($value['invoice_item_id'] ?? 0));
        $gtuByItem = $options !== null && $options['include_gtu']
            ? $this->gtuByItem($items)
            : [];
        [$lines, $taxBuckets] = $this->linesAndTaxBuckets(
            $invoice,
            $items,
            $treatments,
            $gtuByItem,
        );
        $annotations = is_array($ksefTax['annotations'] ?? null) ? $ksefTax['annotations'] : [];
        $hasWdt = $treatments->contains(
            static fn (array $treatment): bool => ($treatment['treatment'] ?? null) === 'wdt',
        );
        $issueDate = $invoice->issue_date?->format('Y-m-d') ?? '';
        $saleDate = $invoice->sale_date?->format('Y-m-d');
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
            'document_kind' => 'VAT',
            'seller' => $this->seller(
                $invoice->seller_snapshot ?? [],
                $options['include_seller_vat_prefix'] ?? $hasWdt,
            ),
            'buyer' => $this->buyer(
                $invoice->buyer_snapshot ?? [],
                $options !== null && $options['include_buyer_contact_data'],
            ),
            'recipient' => $options !== null && $options['include_recipient_data']
                ? $this->recipient($invoice->recipient_snapshot ?? [], $invoice->buyer_snapshot ?? [])
                : null,
            'invoice' => [
                'currency' => strtoupper(trim((string) $invoice->currency)),
                'issue_date' => $issueDate,
                'place_of_issue' => $this->optionalString(data_get($invoice->issuer_snapshot, 'place_of_issue')),
                'number' => trim((string) $invoice->number),
                'sale_date' => $saleDate,
                'tax_buckets' => $taxBuckets,
                'total_gross' => $this->money($invoice->total_gross),
                'annotations' => [
                    'cash_accounting' => ($annotations['cash_accounting'] ?? null) === true,
                    'self_billing' => ($annotations['self_billing'] ?? null) === true,
                    'reverse_charge' => ($annotations['reverse_charge'] ?? null) === true,
                    'split_payment' => ($annotations['split_payment'] ?? null) === true,
                    'exemption' => false,
                    'new_transport_mean' => ($annotations['new_transport_mean'] ?? null) === true,
                    'triangular_transaction' => ($annotations['triangular_transaction'] ?? null) === true,
                    'margin_scheme' => ($annotations['margin_scheme'] ?? null) === true,
                ],
                'additional_descriptions' => $options !== null && $options['include_additional_information']
                    ? $this->additionalDescriptions($invoice->additional_information_text)
                    : [],
                'lines' => $lines,
                'payment' => $options === null
                    ? null
                    : $this->payment($invoice, $options['include_bank_account']),
                'transaction_terms' => $options !== null && $options['include_order_reference']
                    ? $this->transactionTerms($invoice->order_snapshot ?? [])
                    : null,
            ],
            'registrations' => [
                'regon' => $this->optionalString(data_get($invoice->seller_snapshot, 'regon')),
                'bdo' => $this->optionalString(data_get($invoice->seller_snapshot, 'bdo')),
            ],
        ];
    }

    /** @return array<string, bool>|null */
    private function documentOptions(Invoice $invoice): ?array
    {
        $metadata = $invoice->tax_metadata_snapshot ?? [];
        if (! array_key_exists('ksef_document', $metadata)) {
            return null;
        }

        $snapshot = $metadata['ksef_document'];
        if (! is_array($snapshot)) {
            throw $this->documentOptionsError();
        }

        $version = $snapshot['version'] ?? null;
        if (! in_array($version, [1, 2], true)) {
            throw new InvoiceDomainException(
                'ksef_fa3_document_snapshot_version_unsupported',
                'Wersja snapshotu opcji dokumentu KSeF nie jest obsługiwana.',
            );
        }

        $options = $snapshot['options'] ?? null;
        if (! is_array($options)) {
            throw $this->documentOptionsError();
        }

        $keys = array_keys($options);
        sort($keys, SORT_STRING);
        $expectedKeys = $version === 1 ? self::VERSION_1_OPTION_KEYS : self::VERSION_2_OPTION_KEYS;
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw $this->documentOptionsError();
        }

        foreach ($expectedKeys as $key) {
            if (! is_bool($options[$key])) {
                throw $this->documentOptionsError();
            }
        }

        return $options;
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @param  Collection<int, array<string, mixed>>  $treatments
     * @param  array<int, string>  $gtuByItem
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, string>|null>}
     */
    private function linesAndTaxBuckets(
        Invoice $invoice,
        Collection $items,
        Collection $treatments,
        array $gtuByItem,
    ): array {
        $lines = [];
        $buckets = array_fill_keys(self::BUCKETS, null);
        $totalNet = '0.00';
        $totalVat = '0.00';
        $totalGross = '0.00';
        $sourceTaxIdentities = [];
        $invoiceTotalNet = $this->money($invoice->total_net);
        $invoiceTotalVat = $this->money($invoice->total_vat);
        $invoiceTotalGross = $this->money($invoice->total_gross);

        if ($this->compare($this->add($invoiceTotalNet, $invoiceTotalVat), $invoiceTotalGross) !== 0) {
            throw $this->financialError();
        }

        foreach ($items as $item) {
            $treatment = $treatments->get($item->getKey());
            if (! is_array($treatment)) {
                throw $this->financialError();
            }

            $bucketKey = $this->bucketKey($treatment);
            $net = $this->money($item->total_net);
            $vat = $this->money($item->total_vat);
            $gross = $this->money($item->total_gross);
            if ($this->compare($this->add($net, $vat), $gross) !== 0) {
                throw $this->financialError();
            }

            $sourceTaxIdentities[] = (string) ($treatment['tax_identity'] ?? '');
            $buckets[$bucketKey] ??= ['net' => '0.00', 'vat' => '0.00', 'pln_vat' => null];
            $buckets[$bucketKey]['net'] = $this->add($buckets[$bucketKey]['net'], $net);
            $buckets[$bucketKey]['vat'] = $this->add($buckets[$bucketKey]['vat'], $vat);
            $totalNet = $this->add($totalNet, $net);
            $totalVat = $this->add($totalVat, $vat);
            $totalGross = $this->add($totalGross, $gross);

            $lines[] = [
                'position' => $this->positiveInteger($item->position),
                'name' => $this->requiredString($item->name),
                'unit_name' => $this->requiredString($item->unit_name),
                'quantity' => $this->quantity($item->quantity),
                'unit_price_net' => $this->money($item->unit_price_net),
                'total_net' => $net,
                'fa3_rate' => $this->vatRate($treatment['fa3_rate'] ?? null),
                'gtu' => $gtuByItem[(int) $item->getKey()] ?? null,
            ];
        }

        if ($this->compare($totalNet, $invoiceTotalNet) !== 0
            || $this->compare($totalVat, $invoiceTotalVat) !== 0
            || $this->compare($totalGross, $invoiceTotalGross) !== 0) {
            throw $this->financialError();
        }

        if (strtoupper(trim((string) $invoice->currency)) !== 'PLN') {
            $this->addConvertedVat($invoice, $buckets, $sourceTaxIdentities);
        }

        foreach (['domestic_zero', 'wdt', 'export'] as $key) {
            if ($buckets[$key] !== null) {
                $buckets[$key] = ['net' => $buckets[$key]['net']];
            }
        }

        return [$lines, $buckets];
    }

    /** @param array<string, mixed> $treatment */
    private function bucketKey(array $treatment): string
    {
        return match ($treatment['treatment'] ?? null) {
            'domestic_zero' => 'domestic_zero',
            'wdt' => 'wdt',
            'export' => 'export',
            'standard' => match ($treatment['fa3_rate'] ?? null) {
                '23', '22' => 'standard_1',
                '8', '7' => 'standard_2',
                '5' => 'standard_3',
                default => throw $this->financialError(),
            },
            default => throw $this->financialError(),
        };
    }

    /**
     * @param  array<string, array<string, string>|null>  $buckets
     * @param  array<int, string>  $sourceTaxIdentities
     */
    private function addConvertedVat(Invoice $invoice, array &$buckets, array $sourceTaxIdentities): void
    {
        $metadata = $invoice->tax_metadata_snapshot ?? [];
        $conversion = $metadata['currency_conversion'] ?? null;
        $summary = $metadata['converted_tax_summary'] ?? null;
        $currency = strtoupper(trim((string) $invoice->currency));

        if (! is_array($conversion) || ! is_array($summary)
            || ($conversion['version'] ?? null) !== 1
            || strtoupper(trim((string) ($conversion['source_currency'] ?? ''))) !== $currency
            || ($conversion['target_currency'] ?? null) !== 'PLN'
            || ($conversion['rounding_mode'] ?? null) !== 'half_up'
            || ($conversion['result_scale'] ?? null) !== 2
            || ($summary['currency'] ?? null) !== 'PLN'
            || ! is_array($summary['groups'] ?? null)) {
            throw $this->currencyError();
        }

        $groups = [];
        $summaryNet = '0.00';
        $summaryVat = '0.00';
        $summaryGross = '0.00';
        foreach ($summary['groups'] as $group) {
            if (! is_array($group)) {
                throw $this->currencyError();
            }

            $key = $this->taxIdentityKey($group['vat_rate'] ?? null, $group['vat_code'] ?? null);
            if ($key === null || isset($groups[$key])) {
                throw $this->currencyError();
            }

            $net = $this->strictMoney($group['net'] ?? null);
            $vat = $this->strictMoney($group['vat'] ?? null);
            $gross = $this->strictMoney($group['gross'] ?? null);
            if ($this->compare($this->add($net, $vat), $gross) !== 0) {
                throw $this->currencyError();
            }

            $groups[$key] = $vat;
            $summaryNet = $this->add($summaryNet, $net);
            $summaryVat = $this->add($summaryVat, $vat);
            $summaryGross = $this->add($summaryGross, $gross);
        }

        $expectedIdentities = array_values(array_unique($sourceTaxIdentities));
        sort($expectedIdentities, SORT_STRING);
        $actualIdentities = array_keys($groups);
        sort($actualIdentities, SORT_STRING);
        if ($expectedIdentities !== $actualIdentities
            || $this->compare($summaryNet, $this->strictMoney($summary['total_net'] ?? null)) !== 0
            || $this->compare($summaryVat, $this->strictMoney($summary['total_vat'] ?? null)) !== 0
            || $this->compare($summaryGross, $this->strictMoney($summary['total_gross'] ?? null)) !== 0) {
            throw $this->currencyError();
        }

        foreach ([
            'standard_1' => ['rate:23.00', 'rate:22.00'],
            'standard_2' => ['rate:8.00', 'rate:7.00'],
            'standard_3' => ['rate:5.00'],
        ] as $bucketKey => $identities) {
            if ($buckets[$bucketKey] === null) {
                continue;
            }

            $plnVat = '0.00';
            foreach ($identities as $identity) {
                if (isset($groups[$identity])) {
                    $plnVat = $this->add($plnVat, $groups[$identity]);
                }
            }
            $buckets[$bucketKey]['pln_vat'] = $plnVat;
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function seller(array $snapshot, bool $includeVatPrefix): array
    {
        $nip = $this->polishNip($snapshot['tax_id'] ?? null);
        if ($nip === null) {
            throw $this->financialError();
        }

        return [
            'taxpayer_prefix' => $includeVatPrefix ? 'PL' : null,
            'nip' => $nip,
            'name' => $this->requiredString($snapshot['name'] ?? null),
            'address' => $this->address($snapshot, true),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buyer(array $snapshot, bool $includeContacts): array
    {
        $identity = is_array($snapshot['tax_identity'] ?? null) ? $snapshot['tax_identity'] : [];
        $identityType = $this->requiredString($identity['type'] ?? null);
        if (! in_array($identityType, ['pl_nip', 'eu_vat', 'none'], true)) {
            throw $this->financialError();
        }

        $flags = is_array($snapshot['subject_flags'] ?? null) ? $snapshot['subject_flags'] : [];
        if (! is_bool($flags['jst'] ?? null) || ! is_bool($flags['vat_group'] ?? null)) {
            throw $this->financialError();
        }

        return [
            'identity_type' => $identityType,
            'identity_country_code' => $identityType === 'eu_vat'
                ? $this->requiredString($identity['country_code'] ?? null)
                : null,
            'identity_identifier' => $identityType === 'none'
                ? null
                : $this->requiredString($identity['identifier'] ?? null),
            'name' => $this->optionalString($snapshot['company_name'] ?? null)
                ?? $this->optionalString($snapshot['name'] ?? null),
            'address' => $this->address($snapshot, false),
            'contacts' => $includeContacts ? $this->buyerContacts($snapshot) : null,
            'jst' => $flags['jst'],
            'vat_group' => $flags['vat_group'],
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>|null
     */
    private function buyerContacts(array $snapshot): ?array
    {
        $email = $this->snapshotString($snapshot['email'] ?? null, 'ksef_fa3_buyer_contact_invalid');
        $phone = $this->snapshotString($snapshot['phone'] ?? null, 'ksef_fa3_buyer_contact_invalid');
        if ($email === null && $phone === null) {
            return null;
        }
        if ($email !== null
            && ($this->length($email) < 3 || $this->length($email) > 255 || preg_match('/^.+@.+$/u', $email) !== 1)) {
            throw $this->error(
                'ksef_fa3_buyer_contact_invalid',
                'Dane kontaktowe nabywcy są niezgodne z wymaganiami FA(3).',
            );
        }
        if ($phone !== null && $this->length($phone) > 16) {
            throw $this->error(
                'ksef_fa3_buyer_contact_invalid',
                'Dane kontaktowe nabywcy są niezgodne z wymaganiami FA(3).',
            );
        }

        return [
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /** @param array<string, mixed> $recipient
     * @param  array<string, mixed>  $buyer
     * @return array<string, mixed>|null
     */
    private function recipient(array $recipient, array $buyer): ?array
    {
        if (! $this->partyHasMeaningfulData($recipient) || $this->sameParty($recipient, $buyer)) {
            return null;
        }

        $name = $this->snapshotString($recipient['company_name'] ?? null, 'ksef_fa3_recipient_invalid')
            ?? $this->snapshotString($recipient['name'] ?? null, 'ksef_fa3_recipient_invalid');
        $country = $this->countryCode(
            $this->snapshotString($recipient['country_code'] ?? null, 'ksef_fa3_recipient_invalid'),
        );
        $address = $this->address($recipient, false);
        if ($name === null || $this->length($name) > 512 || $country === null || $address === null) {
            throw $this->error(
                'ksef_fa3_recipient_invalid',
                'Snapshot odbiorcy nie zawiera danych wymaganych dla Podmiot3 FA(3).',
            );
        }
        $address['country_code'] = $country;

        return [
            'identity_type' => 'none',
            'name' => $name,
            'address' => $address,
            'role_type' => 'other',
            'role_description' => 'Odbiorca dostawy',
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, ?string>|null
     */
    private function address(array $snapshot, bool $required): ?array
    {
        $street = $this->optionalString($snapshot['street'] ?? null);
        $building = $this->optionalString($snapshot['building_number'] ?? null);
        $apartment = $this->optionalString($snapshot['apartment_number'] ?? null);
        $number = $building;
        if ($apartment !== null) {
            $number = $number !== null ? $number.'/'.$apartment : $apartment;
        }

        $line1 = trim(implode(' ', array_filter(
            [$street, $number],
            static fn (?string $value): bool => $value !== null,
        )));
        if ($line1 === '') {
            if (! $required) {
                return null;
            }

            throw $this->financialError();
        }

        $line2 = trim(implode(' ', array_filter([
            $this->optionalString($snapshot['postal_code'] ?? null),
            $this->optionalString($snapshot['city'] ?? null),
        ], static fn (?string $value): bool => $value !== null)));
        $country = $this->countryCode($this->optionalString($snapshot['country_code'] ?? null));
        if ($country === null) {
            throw $this->financialError();
        }

        return [
            'country_code' => $country,
            'line_1' => $line1,
            'line_2' => $line2 !== '' ? $line2 : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function payment(Invoice $invoice, bool $includeBankAccount): ?array
    {
        $snapshot = $invoice->payment_snapshot;
        if (! is_array($snapshot)) {
            throw $this->paymentError();
        }

        $snapshotDueDate = $this->snapshotString(
            $snapshot['payment_due_date'] ?? null,
            'ksef_fa3_payment_snapshot_invalid',
        );
        if ($snapshotDueDate !== null && ! $this->isDate($snapshotDueDate)) {
            throw $this->paymentError();
        }
        if ($snapshotDueDate !== $invoice->payment_due_date?->format('Y-m-d')) {
            throw $this->paymentError();
        }

        $paymentStatus = $this->snapshotString(
            $snapshot['payment_status'] ?? null,
            'ksef_fa3_payment_snapshot_invalid',
        );
        if (! in_array($paymentStatus, ['unpaid', 'paid'], true)) {
            throw $this->paymentError();
        }

        $paidAmount = $snapshot['paid_amount'] ?? null;
        if (! is_string($paidAmount) && ! is_int($paidAmount)) {
            throw $this->paymentError();
        }
        try {
            $paidAmount = $this->money($paidAmount);
            $amountComparison = $this->compare($paidAmount, (string) $invoice->total_gross);
            $zeroComparison = $this->compare($paidAmount, '0.00');
        } catch (InvoiceDomainException $exception) {
            throw $this->paymentError($exception);
        }
        if ($zeroComparison < 0 || $amountComparison > 0) {
            throw $this->paymentError();
        }

        $paidAt = $this->snapshotString($snapshot['paid_at'] ?? null, 'ksef_fa3_payment_snapshot_invalid');
        $paidDateCandidate = $paidAt === null ? null : $this->calendarDate($paidAt);
        $paidDate = null;
        if ($paymentStatus === 'paid') {
            if ($amountComparison !== 0) {
                throw $this->paymentError();
            }
            $paidDate = $paidDateCandidate;
        } elseif ($amountComparison === 0 && $zeroComparison > 0) {
            throw $this->paymentError();
        }

        [$methodCode, $methodDescription] = $this->paymentMethod($snapshot);
        $bank = $includeBankAccount ? $this->bankAccount($invoice->seller_snapshot ?? []) : null;
        if ($paidDate === null && $snapshotDueDate === null && $methodCode === null
            && $methodDescription === null && $bank === null) {
            return null;
        }

        return [
            'paid_date' => $paidDate,
            'due_date' => $snapshotDueDate,
            'method_code' => $methodCode,
            'method_description' => $methodDescription,
            'bank_account' => $bank,
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array{0: ?string, 1: ?string}
     */
    private function paymentMethod(array $snapshot): array
    {
        if (! array_key_exists('ksef_payment', $snapshot)) {
            $method = $this->snapshotString(
                $snapshot['effective_payment_method'] ?? null,
                'ksef_fa3_payment_method_invalid',
            );
            if ($method === null) {
                return [null, null];
            }
            $code = self::LEGACY_PAYMENT_METHODS[mb_strtolower($method, 'UTF-8')] ?? null;
            if ($code === null && $this->length($method) > 256) {
                throw $this->paymentMappingError();
            }

            return $code !== null ? [$code, null] : [null, $method];
        }

        $mapping = $snapshot['ksef_payment'];
        if (! is_array($mapping) || ($mapping['version'] ?? null) !== 1) {
            throw $this->paymentMappingError();
        }

        $sourceKey = $this->mappingString($mapping['source_key'] ?? null);
        $sourceLabel = $this->mappingString($mapping['source_label'] ?? null);
        $type = $mapping['type'] ?? null;
        if ($type === null) {
            if ($sourceKey !== null || $sourceLabel !== null
                || $this->mappingString($mapping['fa3_code'] ?? null) !== null
                || $this->mappingString($mapping['description'] ?? null) !== null) {
                throw $this->paymentMappingError();
            }

            return [null, null];
        }
        if (! is_string($type) || $sourceKey === null || $sourceLabel === null) {
            throw $this->paymentMappingError();
        }

        $code = $this->mappingString($mapping['fa3_code'] ?? null);
        $description = $this->mappingString($mapping['description'] ?? null);
        if ($type === 'original') {
            if ($code !== null || $description === null || $description !== $sourceLabel
                || $this->length($description) > 256) {
                throw $this->paymentMappingError();
            }

            return [null, $description];
        }

        if (! array_key_exists($type, self::PAYMENT_TYPE_CODES)
            || $description !== null
            || $code !== self::PAYMENT_TYPE_CODES[$type]) {
            throw $this->paymentMappingError();
        }

        return [$code, null];
    }

    /** @param array<string, mixed> $seller
     * @return array<string, ?string>|null
     */
    private function bankAccount(array $seller): ?array
    {
        $account = $this->snapshotString($seller['bank_account'] ?? null, 'ksef_fa3_bank_account_invalid');
        if ($account === null) {
            return null;
        }
        $account = preg_replace('/\s+/u', '', $account);
        if (! is_string($account) || $this->length($account) < 10 || $this->length($account) > 34) {
            throw $this->bankError();
        }

        $swift = $this->snapshotString($seller['bank_swift'] ?? null, 'ksef_fa3_bank_account_invalid');
        if ($swift !== null) {
            $swift = strtoupper((string) preg_replace('/\s+/u', '', $swift));
            if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', $swift) !== 1) {
                throw $this->bankError();
            }
        }
        $name = $this->snapshotString($seller['bank_name'] ?? null, 'ksef_fa3_bank_account_invalid');
        if ($name !== null && $this->length($name) > 256) {
            throw $this->bankError();
        }

        return [
            'number' => $account,
            'swift' => $swift,
            'name' => $name,
        ];
    }

    /** @return array<int, array{key: string, value: string}> */
    private function additionalDescriptions(mixed $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        if (! is_string($text)) {
            throw $this->error(
                'ksef_fa3_additional_information_invalid',
                'Informacje dodatkowe Faktury mają nieprawidłowy format.',
            );
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $values = [];
        foreach (explode("\n", $normalized) as $line) {
            $line = trim($line);
            $line = preg_replace('/\s+/u', ' ', $line);
            if (! is_string($line)) {
                throw $this->error(
                    'ksef_fa3_additional_information_invalid',
                    'Informacje dodatkowe Faktury mają nieprawidłowy format.',
                );
            }
            if ($line === '') {
                continue;
            }

            foreach (mb_str_split($line, 256, 'UTF-8') as $chunk) {
                $values[] = $chunk;
                if (count($values) > 10000) {
                    throw $this->error(
                        'ksef_fa3_additional_information_too_long',
                        'Informacje dodatkowe przekraczają limit elementów FA(3).',
                    );
                }
            }
        }

        return array_map(
            static fn (string $value, int $index): array => [
                'key' => 'Informacja dodatkowa '.($index + 1),
                'value' => $value,
            ],
            $values,
            array_keys($values),
        );
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, ?string>|null
     */
    private function transactionTerms(array $snapshot): ?array
    {
        $number = $this->snapshotString($snapshot['external_id'] ?? null, 'ksef_fa3_order_reference_invalid');
        if ($number === null) {
            return null;
        }
        if ($this->length($number) > 256) {
            throw $this->error(
                'ksef_fa3_order_reference_invalid',
                'Numer zamówienia nie mieści się w kontrakcie FA(3).',
            );
        }

        $purchasedAt = $this->snapshotString(
            $snapshot['purchased_at'] ?? null,
            'ksef_fa3_order_reference_invalid',
        );

        return [
            'date' => $purchasedAt === null ? null : $this->calendarDate($purchasedAt),
            'number' => $number,
        ];
    }

    /** @param Collection<int, InvoiceItem> $items
     * @return array<int, string>
     */
    private function gtuByItem(Collection $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $codes = $item->gtu_codes ?? [];
            if (! is_array($codes)) {
                throw $this->gtuError();
            }

            $unique = [];
            foreach ($codes as $code) {
                if (! is_string($code) || ! in_array($code, self::GTU_CODES, true)) {
                    throw $this->gtuError();
                }
                $unique[$code] = true;
            }
            if (count($unique) > 1) {
                throw $this->error(
                    'ksef_fa3_multiple_gtu_codes',
                    'Pozycja Faktury zawiera więcej niż jedno oznaczenie GTU i nie może zostać odwzorowana w FA(3).',
                );
            }
            if ($unique !== []) {
                $result[(int) $item->getKey()] = (string) array_key_first($unique);
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $party */
    private function partyHasMeaningfulData(array $party): bool
    {
        foreach (['name', 'company_name', 'country_code', 'street', 'building_number', 'apartment_number', 'postal_code', 'city'] as $key) {
            if ($this->optionalString($party[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $left
     * @param  array<string, mixed>  $right
     */
    private function sameParty(array $left, array $right): bool
    {
        return $this->comparableParty($left) === $this->comparableParty($right);
    }

    /** @param array<string, mixed> $party
     * @return array<int, ?string>
     */
    private function comparableParty(array $party): array
    {
        return [
            $this->optionalString($party['company_name'] ?? null)
                ?? $this->optionalString($party['name'] ?? null),
            $this->countryCode($this->optionalString($party['country_code'] ?? null)),
            $this->optionalString($party['street'] ?? null),
            $this->optionalString($party['building_number'] ?? null),
            $this->optionalString($party['apartment_number'] ?? null),
            $this->optionalString($party['postal_code'] ?? null),
            $this->optionalString($party['city'] ?? null),
        ];
    }

    private function taxIdentityKey(mixed $vatRate, mixed $vatCode): ?string
    {
        $code = trim((string) $vatCode);
        if ($code !== '') {
            return 'code:'.strtoupper($code);
        }
        if (! is_string($vatRate) && ! is_int($vatRate)) {
            return null;
        }

        try {
            $rate = BigDecimal::of(str_replace(',', '.', trim((string) $vatRate)))
                ->toScale(0, RoundingMode::Unnecessary);
        } catch (MathException|\InvalidArgumentException) {
            throw $this->currencyError();
        }
        if ($rate->isNegative() || $rate->isGreaterThan(BigDecimal::of(100))) {
            throw $this->currencyError();
        }

        return 'rate:'.$rate->toScale(2);
    }

    private function polishNip(mixed $value): ?string
    {
        $taxId = strtoupper(trim((string) $value));
        $taxId = preg_replace('/[\s.-]+/u', '', $taxId);
        if (! is_string($taxId) || $taxId === '') {
            return null;
        }
        if (str_starts_with($taxId, 'PL')) {
            $taxId = substr($taxId, 2);
        }
        if (preg_match('/^\d{10}$/', $taxId) !== 1) {
            return null;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $checksum = 0;
        foreach ($weights as $position => $weight) {
            $checksum += ((int) $taxId[$position]) * $weight;
        }
        $control = $checksum % 11;

        return $control !== 10 && $control === (int) $taxId[9] ? $taxId : null;
    }

    private function money(mixed $value): string
    {
        return $this->decimal($value, 2, false);
    }

    private function quantity(mixed $value): string
    {
        return $this->decimal($value, 4, true);
    }

    private function vatRate(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['0 KR', '0 WDT', '0 EX'], true)) {
            return $value;
        }

        return $this->decimal($value, 2, true);
    }

    private function decimal(mixed $value, int $scale, bool $trimZeros): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->financialError();
        }

        try {
            $normalized = (string) BigDecimal::of(str_replace(',', '.', trim((string) $value)))
                ->toScale($scale, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->financialError($exception);
        }

        if (! $trimZeros) {
            return $normalized;
        }
        $normalized = rtrim(rtrim($normalized, '0'), '.');

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function strictMoney(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9]\d*)\.\d{2}$/', $value) !== 1) {
            throw $this->currencyError();
        }

        return $this->money($value);
    }

    private function add(string $left, string $right): string
    {
        try {
            return (string) BigDecimal::of($left)
                ->toScale(2, RoundingMode::HalfUp)
                ->plus(BigDecimal::of($right)->toScale(2, RoundingMode::HalfUp))
                ->toScale(2, RoundingMode::HalfUp);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->financialError($exception);
        }
    }

    private function compare(string $left, string $right): int
    {
        try {
            return BigDecimal::of($left)
                ->toScale(2, RoundingMode::HalfUp)
                ->compareTo(BigDecimal::of($right)->toScale(2, RoundingMode::HalfUp));
        } catch (MathException|\InvalidArgumentException $exception) {
            throw $this->financialError($exception);
        }
    }

    private function positiveInteger(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->financialError();
        }
        $value = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw $this->financialError();
        }

        return $value;
    }

    private function calendarDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if ($date === false) {
            throw $this->paymentError();
        }

        return $date->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function countryCode(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    private function snapshotString(mixed $value, string $errorCode): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw $this->error($errorCode, 'Snapshot dokumentu zawiera nieprawidłową wartość tekstową.');
        }

        return $this->optionalString($value);
    }

    private function mappingString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw $this->paymentMappingError();
        }

        return trim($value);
    }

    private function requiredString(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->financialError();
        }
        $value = trim((string) $value);
        if ($value === '') {
            throw $this->financialError();
        }

        return $value;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private function documentOptionsError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_document_options_invalid',
            'Snapshot opcji dokumentu KSeF jest niekompletny.',
        );
    }

    private function financialError(?\Throwable $previous = null): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_financial_snapshot_invalid',
            'Wartości finansowe Faktury są niespójne i nie pozwalają utworzyć FA(3).',
            $previous,
        );
    }

    private function currencyError(?\Throwable $previous = null): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_currency_snapshot_invalid',
            'Snapshot przeliczenia podatku do PLN jest niekompletny lub niespójny.',
            $previous,
        );
    }

    private function paymentError(?\Throwable $previous = null): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_payment_snapshot_invalid',
            'Snapshot płatności Faktury jest niekompletny lub niespójny.',
            $previous,
        );
    }

    private function paymentMappingError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_payment_mapping_invalid',
            'Snapshot formy płatności Faktury jest niekompletny lub niespójny.',
        );
    }

    private function bankError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_bank_account_invalid',
            'Rachunek bankowy w snapshotcie sprzedawcy jest niezgodny z wymaganiami FA(3).',
        );
    }

    private function gtuError(): InvoiceDomainException
    {
        return $this->error(
            'ksef_fa3_gtu_invalid',
            'Pozycja Faktury zawiera nieprawidłowe oznaczenie GTU.',
        );
    }

    private function error(
        string $code,
        string $message,
        ?\Throwable $previous = null,
    ): InvoiceDomainException {
        return new InvoiceDomainException($code, $message, [], $previous);
    }
}
