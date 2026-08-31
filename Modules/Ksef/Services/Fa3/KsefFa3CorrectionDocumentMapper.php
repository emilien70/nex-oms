<?php

namespace Modules\Ksef\Services\Fa3;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Services\KsefFa3BuyerIdentityResolver;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionData;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionDocumentData;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionSourceReference;
use Throwable;

final class KsefFa3CorrectionDocumentMapper
{
    private const BUCKETS = [
        'standard_1',
        'standard_2',
        'standard_3',
        'domestic_zero',
        'wdt',
        'export',
    ];

    public function __construct(
        private readonly KsefFa3CorrectionMapper $corrections,
        private readonly CorrectionTotalsCalculator $correctionTotals,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
        private readonly KsefFa3BuyerIdentityResolver $buyerIdentity,
    ) {}

    public function map(
        Invoice $correction,
        KsefFa3CorrectionSourceReference $sourceReference,
        DateTimeInterface $generatedAt,
    ): KsefFa3CorrectionDocumentData {
        $mapped = $this->corrections->map($correction);
        if ($mapped->rootInvoice->invoiceId !== $sourceReference->rootInvoiceId) {
            throw $this->financialError();
        }

        $correction->loadMissing(['items', 'correctedInvoice']);
        $root = $correction->correctedInvoice;
        if (! $root instanceof Invoice) {
            throw $this->financialError();
        }

        [$lines, $taxBuckets, $hasWdt] = $this->linesAndSummary($correction, $mapped);
        $issueDate = $correction->issue_date?->format('Y-m-d') ?? '';
        $saleDate = $correction->sale_date?->format('Y-m-d');
        if ($saleDate === $issueDate) {
            $saleDate = null;
        }

        return new KsefFa3CorrectionDocumentData(
            generatedAt: DateTimeImmutable::createFromInterface($generatedAt)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            seller: $this->seller(
                $correction->seller_snapshot ?? [],
                $this->sellerVatPrefixOption($root) ?? $hasWdt,
            ),
            buyerAfter: $this->buyer($mapped->buyerAfter),
            buyerBefore: $mapped->buyerBefore !== null ? $this->buyer($mapped->buyerBefore) : null,
            buyerLinkId: $mapped->buyerLinkId,
            invoice: [
                'currency' => strtoupper(trim((string) $correction->currency)),
                'issue_date' => $issueDate,
                'place_of_issue' => $this->optionalString(data_get($correction->issuer_snapshot, 'place_of_issue')),
                'number' => trim((string) $correction->number),
                'sale_date' => $saleDate,
                'total_gross' => $this->money($mapped->differenceTotals['gross']),
            ],
            taxBuckets: $taxBuckets,
            annotations: $this->annotations($root),
            lines: $lines,
            sourceReference: $sourceReference,
            reason: $mapped->reason,
        );
    }

    /**
     * @return array{0: list<array{position: int, before: array<string, string|int>, after: array<string, string|int>}>, 1: array<string, array<string, string>|null>, 2: bool}
     */
    private function linesAndSummary(Invoice $correction, KsefFa3CorrectionData $mapped): array
    {
        $treatments = data_get($correction->tax_metadata_snapshot, 'ksef_correction.line_treatments');
        if (! is_array($treatments)) {
            throw $this->financialError();
        }

        $itemsByPosition = [];
        foreach ($correction->items as $item) {
            if (isset($itemsByPosition[$item->position])) {
                throw $this->financialError();
            }
            $itemsByPosition[$item->position] = $item;
        }

        $byItemId = [];
        $hasWdt = false;
        foreach ($treatments as $treatment) {
            $itemId = is_array($treatment) ? ($treatment['invoice_item_id'] ?? null) : null;
            if (! is_int($itemId) || isset($byItemId[$itemId])) {
                throw $this->financialError();
            }
            $byItemId[$itemId] = $treatment;
            $hasWdt = $hasWdt
                || data_get($treatment, 'before.treatment') === 'wdt'
                || data_get($treatment, 'after.treatment') === 'wdt';
        }

        $lines = [];
        $buckets = array_fill_keys(self::BUCKETS, null);
        $totalNet = '0.00';
        $totalVat = '0.00';
        $totalGross = '0.00';

        foreach ($mapped->changedLines as $changedLine) {
            $item = $itemsByPosition[$changedLine->logicalPosition] ?? null;
            $treatment = $item !== null ? ($byItemId[$item->getKey()] ?? null) : null;
            if (! is_array($treatment)
                || ($treatment['position'] ?? null) !== $changedLine->logicalPosition
                || ! is_array($treatment['before'] ?? null)
                || ! is_array($treatment['after'] ?? null)) {
                throw $this->financialError();
            }

            $before = $this->line($changedLine->before, $treatment['before']);
            $after = $this->line($changedLine->after, $treatment['after']);
            $this->assertLineTotals($before);
            $this->assertLineTotals($after);

            $this->addToBucket($buckets, $treatment['after'], $after, false);
            $this->addToBucket($buckets, $treatment['before'], $before, true);
            $totalNet = $this->decimal->add($totalNet, $this->decimal->subtract($after['total_net'], $before['total_net']));
            $totalVat = $this->decimal->add($totalVat, $this->decimal->subtract($after['total_vat'], $before['total_vat']));
            $totalGross = $this->decimal->add($totalGross, $this->decimal->subtract($after['total_gross'], $before['total_gross']));
            $lines[] = [
                'position' => $changedLine->logicalPosition,
                'before' => $before,
                'after' => $after,
            ];
        }

        usort($lines, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);
        if ($this->decimal->compare($totalNet, $mapped->differenceTotals['net']) !== 0
            || $this->decimal->compare($totalVat, $mapped->differenceTotals['vat']) !== 0
            || $this->decimal->compare($totalGross, $mapped->differenceTotals['gross']) !== 0
            || $this->decimal->compare($this->decimal->add($totalNet, $totalVat), $totalGross) !== 0) {
            throw $this->financialError();
        }

        $currency = strtoupper(trim((string) $correction->currency));
        $difference = [
            'net' => $mapped->differenceTotals['net'],
            'vat' => $mapped->differenceTotals['vat'],
            'gross' => $mapped->differenceTotals['gross'],
            'tax_summary_snapshot' => $mapped->differenceTotals['taxSummary'],
        ];
        if ($currency !== 'PLN' && $this->correctionTotals->isMonetary($difference)) {
            $this->addConvertedVat($correction, $buckets, $mapped->differenceTotals['taxSummary']);
        }

        foreach ($buckets as $key => $bucket) {
            if (is_array($bucket)
                && $this->decimal->compare($bucket['net'], '0.00') === 0
                && $this->decimal->compare($bucket['vat'], '0.00') === 0
                && (! isset($bucket['pln_vat']) || $this->decimal->compare($bucket['pln_vat'], '0.00') === 0)) {
                $buckets[$key] = null;
            }
        }

        return [$lines, $buckets, $hasWdt];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $treatment
     * @return array<string, string|int>
     */
    private function line(array $snapshot, array $treatment): array
    {
        $fa3Rate = $treatment['fa3_rate'] ?? null;
        if (! is_string($fa3Rate) || $fa3Rate === '') {
            throw $this->financialError();
        }

        return [
            'name' => trim((string) ($snapshot['name'] ?? '')),
            'unit_name' => trim((string) ($snapshot['unit_name'] ?? '')),
            'quantity' => $this->quantity($snapshot['quantity'] ?? null),
            'unit_price_net' => $this->money($snapshot['unit_price_net'] ?? null),
            'total_net' => $this->money($snapshot['total_net'] ?? null),
            'total_vat' => $this->money($snapshot['total_vat'] ?? null),
            'total_gross' => $this->money($snapshot['total_gross'] ?? null),
            'fa3_rate' => $fa3Rate,
        ];
    }

    /** @param array<string, string|int> $line */
    private function assertLineTotals(array $line): void
    {
        if ($this->decimal->compare(
            $this->decimal->add((string) $line['total_net'], (string) $line['total_vat']),
            (string) $line['total_gross'],
        ) !== 0) {
            throw $this->financialError();
        }
    }

    /**
     * @param  array<string, array<string, string>|null>  $buckets
     * @param  array<string, mixed>  $treatment
     * @param  array<string, string|int>  $line
     */
    private function addToBucket(array &$buckets, array $treatment, array $line, bool $subtract): void
    {
        $key = $this->bucketKey($treatment);
        $buckets[$key] ??= ['net' => '0.00', 'vat' => '0.00'];
        foreach (['net' => 'total_net', 'vat' => 'total_vat'] as $bucketField => $lineField) {
            $buckets[$key][$bucketField] = $subtract
                ? $this->decimal->subtract($buckets[$key][$bucketField], (string) $line[$lineField])
                : $this->decimal->add($buckets[$key][$bucketField], (string) $line[$lineField]);
        }
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
     * @param  array<int, mixed>  $differenceTaxSummary
     */
    private function addConvertedVat(Invoice $correction, array &$buckets, array $differenceTaxSummary): void
    {
        try {
            $metadata = $correction->tax_metadata_snapshot ?? [];
            $conversion = $metadata['currency_conversion'] ?? null;
            $summary = $metadata['converted_tax_summary'] ?? null;
            $currency = strtoupper(trim((string) $correction->currency));

            if (! is_array($conversion) || ! is_array($summary)
                || ($conversion['version'] ?? null) !== 1
                || strtoupper(trim((string) ($conversion['source_currency'] ?? ''))) !== $currency
                || ($conversion['target_currency'] ?? null) !== 'PLN'
                || ($conversion['rounding_mode'] ?? null) !== 'half_up'
                || ($conversion['result_scale'] ?? null) !== 2
                || ($summary['currency'] ?? null) !== 'PLN'
                || ! is_array($summary['groups'] ?? null)) {
                throw $this->financialError();
            }

            $groups = [];
            $summaryTotals = ['net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];
            foreach ($summary['groups'] as $group) {
                if (! is_array($group)) {
                    throw $this->financialError();
                }
                $identity = $this->taxIdentity->key($this->taxIdentity->normalize(
                    $group['vat_rate'] ?? null,
                    $group['vat_code'] ?? null,
                ));
                if ($identity === null || isset($groups[$identity])) {
                    throw $this->financialError();
                }
                $groups[$identity] = $this->strictMoney($group['vat'] ?? null);
                foreach ($summaryTotals as $field => $value) {
                    $summaryTotals[$field] = $this->decimal->add($value, $this->strictMoney($group[$field] ?? null));
                }
            }

            $expectedIdentities = [];
            foreach ($differenceTaxSummary as $group) {
                if (! is_array($group)) {
                    throw $this->financialError();
                }
                $identity = $this->taxIdentity->key($this->taxIdentity->normalize(
                    $group['vat_rate'] ?? null,
                    $group['vat_code'] ?? null,
                ));
                if ($identity === null) {
                    throw $this->financialError();
                }
                $expectedIdentities[] = $identity;
            }
            $expectedIdentities = array_values(array_unique($expectedIdentities));
            sort($expectedIdentities, SORT_STRING);
            $actualIdentities = array_keys($groups);
            sort($actualIdentities, SORT_STRING);
            if ($expectedIdentities !== $actualIdentities) {
                throw $this->financialError();
            }
            foreach ($summaryTotals as $field => $value) {
                if ($this->decimal->compare($value, $this->strictMoney($summary['total_'.$field] ?? null)) !== 0) {
                    throw $this->financialError();
                }
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
                        $plnVat = $this->decimal->add($plnVat, $groups[$identity]);
                    }
                }
                $buckets[$bucketKey]['pln_vat'] = $plnVat;
            }
        } catch (InvoiceDomainException $exception) {
            if ($exception->errorCode() === 'ksef_fa3_correction_xml_financial_mapping_invalid') {
                throw $exception;
            }

            throw $this->financialError($exception);
        } catch (Throwable $exception) {
            throw $this->financialError($exception);
        }
    }

    /** @return array<string, bool> */
    private function annotations(Invoice $root): array
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
            || ! array_key_exists('exemption', $annotations)
            || $annotations['exemption'] !== null
            || ($annotations['new_transport_mean'] ?? null) !== false
            || ($annotations['triangular_transaction'] ?? null) !== false
            || ($annotations['margin_scheme'] ?? null) !== false) {
            throw new InvoiceDomainException(
                'ksef_fa3_correction_annotations_unresolved',
                'Nie można jednoznacznie ustalić historycznych adnotacji Faktury korygowanej.',
            );
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

    private function sellerVatPrefixOption(Invoice $root): ?bool
    {
        $snapshot = data_get($root->tax_metadata_snapshot, 'ksef_document');
        if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 2) {
            return null;
        }

        $value = data_get($snapshot, 'options.include_seller_vat_prefix');

        return is_bool($value) ? $value : null;
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function seller(array $snapshot, bool $includeVatPrefix): array
    {
        return [
            'taxpayer_prefix' => $includeVatPrefix ? 'PL' : null,
            'nip' => $this->buyerIdentity->normalizePolishNip($snapshot['tax_id'] ?? null) ?? '',
            'name' => trim((string) ($snapshot['name'] ?? '')),
            'address' => $this->address($snapshot),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buyer(array $snapshot): array
    {
        $snapshot = $this->buyerIdentity->withSemantics($snapshot);
        $identity = is_array($snapshot['tax_identity'] ?? null) ? $snapshot['tax_identity'] : [];

        return [
            'identity_type' => (string) ($identity['type'] ?? ''),
            'identity_country_code' => $this->optionalString($identity['country_code'] ?? null),
            'identity_identifier' => $this->optionalString($identity['identifier'] ?? null),
            'name' => $this->optionalString($snapshot['company_name'] ?? null)
                ?? $this->optionalString($snapshot['name'] ?? null),
            'address' => $this->address($snapshot),
            'jst' => (bool) data_get($snapshot, 'subject_flags.jst'),
            'vat_group' => (bool) data_get($snapshot, 'subject_flags.vat_group'),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    private function address(array $snapshot): array
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
        $line2 = trim(implode(' ', array_filter([
            $this->optionalString($snapshot['postal_code'] ?? null),
            $this->optionalString($snapshot['city'] ?? null),
        ], static fn (?string $value): bool => $value !== null)));

        return array_filter([
            'country_code' => strtoupper(trim((string) ($snapshot['country_code'] ?? ''))),
            'line_1' => $line1,
            'line_2' => $line2 !== '' ? $line2 : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    private function money(mixed $value): string
    {
        return $this->decimalValue($value, 2);
    }

    private function quantity(mixed $value): string
    {
        return rtrim(rtrim($this->decimalValue($value, 4), '0'), '.');
    }

    private function decimalValue(mixed $value, int $scale): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw $this->financialError();
        }

        try {
            return $this->decimal->normalize($value, $scale);
        } catch (InvoiceDomainException $exception) {
            throw $this->financialError($exception);
        }
    }

    private function strictMoney(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9]\d*)\.\d{2}$/', $value) !== 1) {
            throw $this->financialError();
        }

        return $this->decimal->normalize($value, 2);
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function financialError(?Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_correction_xml_financial_mapping_invalid',
            'Wartości finansowe Korekty są niespójne i nie pozwalają utworzyć FA(3).',
            [],
            $previous,
        );
    }
}
