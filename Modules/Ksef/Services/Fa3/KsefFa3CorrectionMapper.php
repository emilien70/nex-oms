<?php

namespace Modules\Ksef\Services\Fa3;

use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionData;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionLine;
use Modules\Ksef\ValueObjects\Fa3\KsefFa3CorrectionRootInvoice;
use Throwable;
use UnexpectedValueException;

class KsefFa3CorrectionMapper
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

    private const LINE_DECIMAL_SCALES = [
        'quantity' => 4,
        'unit_price_net' => 4,
        'unit_price_gross' => 4,
        'total_net' => 2,
        'total_vat' => 2,
        'total_gross' => 2,
    ];

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly CorrectionTotalsCalculator $correctionTotals,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
    ) {}

    public function map(Invoice $correction): KsefFa3CorrectionData
    {
        if (! $correction->isCorrection()) {
            throw $this->documentInvalid();
        }

        $rootInvoice = $this->rootInvoice($correction);
        $reason = trim((string) $correction->correction_reason);
        if ($reason === '') {
            throw new InvoiceDomainException(
                'ksef_fa3_correction_reason_missing',
                'Korekta nie zawiera wymaganego powodu korekty.',
            );
        }

        $buyerBefore = data_get($correction->order_snapshot, 'correction.buyer_before');
        $buyerAfter = $correction->buyer_snapshot;
        if (! is_array($buyerBefore) || ! is_array($buyerAfter)) {
            throw $this->documentInvalid();
        }

        $buyerChanged = $this->canonicalBuyer($buyerBefore) !== $this->canonicalBuyer($buyerAfter);
        $items = $correction->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();
        $lineData = $this->lineData($items);

        return new KsefFa3CorrectionData(
            kind: 'KOR',
            reason: $reason,
            type: null,
            rootInvoice: $rootInvoice,
            buyerBefore: $buyerChanged ? $buyerBefore : null,
            buyerAfter: $buyerAfter,
            buyerLinkId: $buyerChanged ? 'NB/01' : null,
            changedLines: $lineData['changedLines'],
            differenceTotals: $this->differenceTotals($correction, $lineData['calculatorItems']),
        );
    }

    private function rootInvoice(Invoice $correction): KsefFa3CorrectionRootInvoice
    {
        if ($correction->corrected_invoice_id === null) {
            throw $this->sourceInvalid();
        }

        $correction->loadMissing('correctedInvoice');
        $root = $correction->correctedInvoice;
        $number = $root instanceof Invoice ? trim((string) $root->number) : '';

        if (! $root instanceof Invoice
            || ! $root->isInvoice()
            || $root->status !== InvoiceDocumentStatus::Issued
            || $number === ''
            || $root->issue_date === null
            || (int) $root->order_id !== (int) $correction->order_id) {
            throw $this->sourceInvalid();
        }

        return new KsefFa3CorrectionRootInvoice(
            invoiceId: (int) $root->getKey(),
            number: $number,
            localIssueDate: $root->issue_date->toDateString(),
        );
    }

    /**
     * @param  array<int, InvoiceItem>  $items
     * @return array{
     *     changedLines: array<int, KsefFa3CorrectionLine>,
     *     calculatorItems: array<int, array{correction_before_snapshot: array<string, mixed>, correction_after_snapshot: array<string, mixed>}>
     * }
     */
    private function lineData(array $items): array
    {
        $changedLines = [];
        $calculatorItems = [];

        foreach ($items as $item) {
            $snapshots = $this->validatedLineSnapshots($item);
            $calculatorItems[] = [
                'correction_before_snapshot' => $snapshots['before'],
                'correction_after_snapshot' => $snapshots['after'],
            ];

            if ($snapshots['canonicalBefore'] === $snapshots['canonicalAfter']) {
                continue;
            }

            $changedLines[] = new KsefFa3CorrectionLine(
                logicalPosition: $snapshots['afterPosition'],
                before: $snapshots['before'],
                after: $snapshots['after'],
            );
        }

        return [
            'changedLines' => $changedLines,
            'calculatorItems' => $calculatorItems,
        ];
    }

    /**
     * @return array{
     *     before: array<string, mixed>,
     *     after: array<string, mixed>,
     *     canonicalBefore: array<string, mixed>,
     *     canonicalAfter: array<string, mixed>,
     *     afterPosition: int
     * }
     */
    private function validatedLineSnapshots(InvoiceItem $item): array
    {
        $reason = 'missing_snapshot';

        try {
            $before = $item->correction_before_snapshot;
            $after = $item->correction_after_snapshot;
            $difference = $item->correction_difference_snapshot;

            if (! is_array($before) || ! is_array($after) || ! is_array($difference)) {
                throw new UnexpectedValueException('Missing correction line snapshot.');
            }

            $reason = 'before_snapshot_invalid';
            $this->linePosition($before);
            $canonicalBefore = $this->canonicalLine($before);
            $reason = 'after_snapshot_invalid';
            $afterPosition = $this->linePosition($after);
            $canonicalAfter = $this->canonicalLine($after);
            $reason = 'difference_snapshot_invalid';
            $differencePosition = $this->linePosition($difference);
            $canonicalDifference = $this->canonicalLine($difference);

            $reason = 'difference_mismatch';
            $this->assertLineDifference(
                $canonicalBefore,
                $canonicalAfter,
                $canonicalDifference,
                $afterPosition,
                $differencePosition,
            );

            return [
                'before' => $before,
                'after' => $after,
                'canonicalBefore' => $canonicalBefore,
                'canonicalAfter' => $canonicalAfter,
                'afterPosition' => $afterPosition,
            ];
        } catch (Throwable $exception) {
            throw $this->lineSnapshotInvalid($item, $reason, $exception);
        }
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

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function canonicalLine(array $snapshot): array
    {
        $canonical = [
            'line_type' => $this->lineString($snapshot, 'line_type'),
            'name' => $this->lineString($snapshot, 'name'),
            'description' => $this->lineString($snapshot, 'description'),
            'unit_name' => $this->lineString($snapshot, 'unit_name'),
        ];

        foreach (self::LINE_DECIMAL_SCALES as $field => $scale) {
            $canonical[$field] = $this->lineDecimal($snapshot, $field, $scale);
        }

        $vatRate = $this->lineString($snapshot, 'vat_rate');
        $canonical['vat_rate'] = $vatRate === null
            ? null
            : $this->decimal->normalize($vatRate, 2);
        $vatCode = $this->lineString($snapshot, 'vat_code');
        $canonical['vat_code'] = $vatCode !== null ? strtoupper($vatCode) : null;

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $difference
     */
    private function assertLineDifference(
        array $before,
        array $after,
        array $difference,
        int $afterPosition,
        int $differencePosition,
    ): void {
        if ($differencePosition !== $afterPosition) {
            throw new UnexpectedValueException('Correction line position mismatch.');
        }

        foreach (self::LINE_DECIMAL_SCALES as $field => $scale) {
            $expected = $this->decimal->subtract($after[$field], $before[$field], $scale);
            if ($this->decimal->compare($difference[$field], $expected, $scale) !== 0) {
                throw new UnexpectedValueException('Correction line decimal difference mismatch.');
            }
        }

        foreach (['line_type', 'name', 'description', 'unit_name', 'vat_rate', 'vat_code'] as $field) {
            if ($difference[$field] !== $after[$field]) {
                throw new UnexpectedValueException('Correction line after-state field mismatch.');
            }
        }
    }

    /**
     * @param  array<int, array{correction_before_snapshot: array<string, mixed>, correction_after_snapshot: array<string, mixed>}>  $items
     * @return array{net: string, vat: string, gross: string, taxSummary: array<int, mixed>}
     */
    private function differenceTotals(Invoice $correction, array $items): array
    {
        try {
            $stored = $correction->correction_totals_snapshot;
            if (! is_array($stored)) {
                throw new UnexpectedValueException('Missing correction totals snapshot.');
            }

            $recomputed = $this->correctionTotals->calculate($items);
            foreach (['before', 'after', 'difference'] as $state) {
                $storedState = $stored[$state] ?? null;
                $recomputedState = $recomputed[$state] ?? null;
                if (! is_array($storedState) || ! is_array($recomputedState)) {
                    throw new UnexpectedValueException('Missing correction totals state.');
                }

                $this->assertTotalsStateMatches($storedState, $recomputedState);
            }

            $difference = $stored['difference'];
            $result = $this->normalizedTotals($difference);
            foreach (['net' => 'total_net', 'vat' => 'total_vat', 'gross' => 'total_gross'] as $key => $attribute) {
                $documentValue = $correction->getAttribute($attribute);
                if (! is_string($documentValue) && ! is_int($documentValue)) {
                    throw new UnexpectedValueException('Invalid correction difference total.');
                }

                if ($this->decimal->compare($result[$key], (string) $documentValue) !== 0) {
                    throw new UnexpectedValueException('Correction difference total mismatch.');
                }
            }

            return [
                ...$result,
                'taxSummary' => $difference['tax_summary_snapshot'],
            ];
        } catch (Throwable $exception) {
            throw new InvoiceDomainException(
                'ksef_fa3_correction_totals_invalid',
                'Snapshot różnic Korekty jest niekompletny lub niespójny.',
                [],
                $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $recomputed
     */
    private function assertTotalsStateMatches(array $stored, array $recomputed): void
    {
        if ($this->normalizedTotals($stored) !== $this->normalizedTotals($recomputed)) {
            throw new UnexpectedValueException('Correction totals mismatch.');
        }

        $storedSummary = $stored['tax_summary_snapshot'] ?? null;
        $recomputedSummary = $recomputed['tax_summary_snapshot'] ?? null;
        if (! is_array($storedSummary) || ! is_array($recomputedSummary)
            || $this->canonicalTaxSummary($storedSummary) !== $this->canonicalTaxSummary($recomputedSummary)) {
            throw new UnexpectedValueException('Correction tax summary mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array{net: string, vat: string, gross: string}
     */
    private function normalizedTotals(array $totals): array
    {
        $normalized = [];

        foreach (['net', 'vat', 'gross'] as $component) {
            $value = $totals[$component] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                throw new UnexpectedValueException('Invalid correction total.');
            }

            $normalized[$component] = $this->decimal->normalize($value, 2);
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $summary
     * @return array<string, array{vat_rate: ?string, vat_code: ?string, net: string, vat: string, gross: string}>
     */
    private function canonicalTaxSummary(array $summary): array
    {
        $canonical = [];

        foreach ($summary as $group) {
            if (! is_array($group)
                || ! array_key_exists('vat_rate', $group)
                || ! array_key_exists('vat_code', $group)) {
                throw new UnexpectedValueException('Invalid correction tax group.');
            }

            foreach (['vat_rate', 'vat_code'] as $field) {
                $value = $group[$field];
                if ($value !== null && ! is_string($value) && ! is_int($value)) {
                    throw new UnexpectedValueException('Invalid correction tax group identity.');
                }
            }

            $identity = $this->taxIdentity->normalize($group['vat_rate'], $group['vat_code']);
            $key = $this->taxIdentity->key($identity);
            if ($key === null || array_key_exists($key, $canonical)) {
                throw new UnexpectedValueException('Invalid correction tax group identity.');
            }

            $canonical[$key] = [
                ...$identity,
                ...$this->normalizedTotals($group),
            ];
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /** @param array<string, mixed> $snapshot */
    private function linePosition(array $snapshot): int
    {
        $value = $this->lineValue($snapshot, 'position');
        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException('Invalid correction line position.');
        }

        $position = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($position === false) {
            throw new UnexpectedValueException('Invalid correction line position.');
        }

        return $position;
    }

    /** @param array<string, mixed> $snapshot */
    private function lineDecimal(array $snapshot, string $field, int $scale): string
    {
        $value = $this->lineValue($snapshot, $field);
        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException('Invalid correction line decimal.');
        }

        return $this->decimal->normalize($value, $scale);
    }

    /** @param array<string, mixed> $snapshot */
    private function lineString(array $snapshot, string $field): ?string
    {
        $value = $this->lineValue($snapshot, $field);
        if ($value !== null && ! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException('Invalid correction line text.');
        }

        return $this->optionalString($value);
    }

    /** @param array<string, mixed> $snapshot */
    private function lineValue(array $snapshot, string $field): mixed
    {
        if (! array_key_exists($field, $snapshot)) {
            throw new UnexpectedValueException('Missing correction line field.');
        }

        return $snapshot[$field];
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function documentInvalid(?Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_correction_document_invalid',
            'Dokument nie zawiera kompletnego kontraktu danych Korekty FA(3).',
            [],
            $previous,
        );
    }

    private function lineSnapshotInvalid(
        InvoiceItem $item,
        string $reason,
        ?Throwable $previous = null,
    ): InvoiceDomainException {
        return new InvoiceDomainException(
            'ksef_fa3_correction_line_snapshot_invalid',
            'Snapshot różnicy pozycji Korekty jest niekompletny lub niespójny.',
            [
                'position' => (int) $item->position,
                'reason' => $reason,
            ],
            $previous,
        );
    }

    private function sourceInvalid(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_correction_source_invalid',
            'Korekta nie wskazuje poprawnej, wystawionej Faktury źródłowej.',
        );
    }
}
