<?php

namespace Modules\Ksef\Services\Fa3;

use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
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

        return new KsefFa3CorrectionData(
            kind: 'KOR',
            reason: $reason,
            type: null,
            rootInvoice: $rootInvoice,
            buyerBefore: $buyerChanged ? $buyerBefore : null,
            buyerAfter: $buyerAfter,
            buyerLinkId: $buyerChanged ? 'NB/01' : null,
            changedLines: $this->changedLines($correction),
            differenceTotals: $this->differenceTotals($correction),
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

    /** @return array<int, KsefFa3CorrectionLine> */
    private function changedLines(Invoice $correction): array
    {
        return $correction->items()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (InvoiceItem $item): ?KsefFa3CorrectionLine {
                $before = $item->correction_before_snapshot;
                $after = $item->correction_after_snapshot;
                $difference = $item->correction_difference_snapshot;

                if (! is_array($before) || ! is_array($after) || ! is_array($difference)) {
                    throw $this->documentInvalid();
                }

                if ($this->canonicalLine($before) === $this->canonicalLine($after)) {
                    return null;
                }

                $position = (int) ($after['position'] ?? $item->position);
                if ($position < 1) {
                    throw $this->documentInvalid();
                }

                return new KsefFa3CorrectionLine(
                    logicalPosition: $position,
                    before: $before,
                    after: $after,
                );
            })
            ->filter()
            ->values()
            ->all();
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
        try {
            $canonical = [
                'line_type' => $this->optionalString($snapshot['line_type'] ?? null),
                'name' => $this->optionalString($snapshot['name'] ?? null),
                'description' => $this->optionalString($snapshot['description'] ?? null),
                'unit_name' => $this->optionalString($snapshot['unit_name'] ?? null),
            ];

            foreach (self::LINE_DECIMAL_SCALES as $field => $scale) {
                if (! array_key_exists($field, $snapshot)
                    || (! is_string($snapshot[$field]) && ! is_int($snapshot[$field]))) {
                    throw new UnexpectedValueException('Missing correction line decimal.');
                }

                $canonical[$field] = $this->decimal->normalize($snapshot[$field], $scale);
            }

            $vatRate = $snapshot['vat_rate'] ?? null;
            $canonical['vat_rate'] = $vatRate === null || $vatRate === ''
                ? null
                : $this->decimal->normalize((string) $vatRate, 2);
            $vatCode = $this->optionalString($snapshot['vat_code'] ?? null);
            $canonical['vat_code'] = $vatCode !== null ? strtoupper($vatCode) : null;

            return $canonical;
        } catch (Throwable $exception) {
            throw $this->documentInvalid($exception);
        }
    }

    /** @return array{net: string, vat: string, gross: string, taxSummary: array<int, mixed>} */
    private function differenceTotals(Invoice $correction): array
    {
        try {
            $difference = data_get($correction->correction_totals_snapshot, 'difference');
            if (! is_array($difference)
                || ! is_array($difference['tax_summary_snapshot'] ?? null)) {
                throw new UnexpectedValueException('Missing correction difference totals.');
            }

            $result = [];
            foreach (['net' => 'total_net', 'vat' => 'total_vat', 'gross' => 'total_gross'] as $key => $attribute) {
                $value = $difference[$key] ?? null;
                $documentValue = $correction->getAttribute($attribute);
                if ((! is_string($value) && ! is_int($value))
                    || (! is_string($documentValue) && ! is_int($documentValue))) {
                    throw new UnexpectedValueException('Invalid correction difference total.');
                }

                $result[$key] = $this->decimal->normalize($value, 2);
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

    private function sourceInvalid(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'ksef_fa3_correction_source_invalid',
            'Korekta nie wskazuje poprawnej, wystawionej Faktury źródłowej.',
        );
    }
}
