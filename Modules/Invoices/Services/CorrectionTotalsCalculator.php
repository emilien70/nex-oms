<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;

class CorrectionTotalsCalculator
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
        private readonly InvoiceFinancialValueValidator $financial,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{
     *     before: array{net: string, vat: string, gross: string, tax_summary_snapshot: array<int, array<string, mixed>>},
     *     after: array{net: string, vat: string, gross: string, tax_summary_snapshot: array<int, array<string, mixed>>},
     *     difference: array{net: string, vat: string, gross: string, tax_summary_snapshot: array<int, array<string, mixed>>}
     * }
     */
    public function calculate(
        array $items,
        string $beforeKey = 'correction_before_snapshot',
        string $afterKey = 'correction_after_snapshot',
    ): array {
        $before = $this->documentTotals($items, $beforeKey);
        $after = $this->documentTotals($items, $afterKey);

        return [
            'before' => $this->compact($before),
            'after' => $this->compact($after),
            'difference' => $this->difference(
                $before['tax_summary_snapshot'],
                $after['tax_summary_snapshot'],
            ),
        ];
    }

    /** @param array<string, mixed> $difference */
    public function isMonetary(array $difference): bool
    {
        foreach (['net', 'vat', 'gross'] as $component) {
            if ($this->decimal->compare((string) ($difference[$component] ?? '0.00'), '0.00') !== 0) {
                return true;
            }
        }

        foreach ((array) ($difference['tax_summary_snapshot'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach (['net', 'vat', 'gross'] as $component) {
                if ($this->decimal->compare((string) ($group[$component] ?? '0.00'), '0.00') !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function documentTotals(array $items, string $snapshotKey): array
    {
        return $this->totals->calculateEditedDocument(
            array_map(static fn (array $item): array => $item[$snapshotKey], $items),
            '0.00',
        );
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array{net: string, vat: string, gross: string, tax_summary_snapshot: array<int, array<string, mixed>>}
     */
    private function compact(array $totals): array
    {
        return [
            'net' => $totals['total_net'],
            'vat' => $totals['total_vat'],
            'gross' => $totals['total_gross'],
            'tax_summary_snapshot' => $totals['tax_summary_snapshot'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array{net: string, vat: string, gross: string, tax_summary_snapshot: array<int, array<string, mixed>>}
     */
    private function difference(array $before, array $after): array
    {
        $beforeGroups = $this->indexGroups($before);
        $afterGroups = $this->indexGroups($after);
        $keys = array_unique(array_merge(array_keys($beforeGroups), array_keys($afterGroups)));
        sort($keys, SORT_STRING);

        $groups = [];
        $totals = ['net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];

        foreach ($keys as $key) {
            $identity = $afterGroups[$key] ?? $beforeGroups[$key];
            $beforeGroup = $beforeGroups[$key] ?? $this->zeroGroup($identity);
            $afterGroup = $afterGroups[$key] ?? $this->zeroGroup($identity);
            $group = [
                'vat_rate' => $identity['vat_rate'],
                'vat_code' => $identity['vat_code'],
                'net' => $this->decimal->subtract($afterGroup['net'], $beforeGroup['net']),
                'vat' => $this->decimal->subtract($afterGroup['vat'], $beforeGroup['vat']),
                'gross' => $this->decimal->subtract($afterGroup['gross'], $beforeGroup['gross']),
            ];

            if ($this->isZeroGroup($group)) {
                continue;
            }

            foreach (['net', 'vat', 'gross'] as $component) {
                $totals[$component] = $this->decimal->add($totals[$component], $group[$component]);
                $totals[$component] = $this->financial->assertCorrectionDifference($totals[$component]);
            }
            $groups[] = $group;
        }

        return [
            'net' => $this->financial->assertCorrectionDifference($totals['net']),
            'vat' => $this->financial->assertCorrectionDifference($totals['vat']),
            'gross' => $this->financial->assertCorrectionDifference($totals['gross']),
            'tax_summary_snapshot' => $groups,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, array{vat_rate: ?string, vat_code: ?string, net: string, vat: string, gross: string}>
     */
    private function indexGroups(array $groups): array
    {
        $indexed = [];

        foreach ($groups as $group) {
            $identity = $this->taxIdentity->normalize(
                $group['vat_rate'] ?? null,
                $group['vat_code'] ?? null,
            );
            $key = $this->taxIdentity->key($identity);
            if ($key === null) {
                throw new InvoiceDomainException(
                    'invoice_tax_calculation_failed',
                    'Nie można prawidłowo obliczyć wartości podatkowych dokumentu.',
                );
            }
            $indexed[$key] ??= [
                ...$identity,
                'net' => '0.00',
                'vat' => '0.00',
                'gross' => '0.00',
            ];
            $indexed[$key]['net'] = $this->decimal->add($indexed[$key]['net'], (string) $group['net']);
            $indexed[$key]['vat'] = $this->decimal->add($indexed[$key]['vat'], (string) $group['vat']);
            $indexed[$key]['gross'] = $this->decimal->add($indexed[$key]['gross'], (string) $group['gross']);
            $indexed[$key]['net'] = $this->financial->assertCorrectionDifference($indexed[$key]['net']);
            $indexed[$key]['vat'] = $this->financial->assertCorrectionDifference($indexed[$key]['vat']);
            $indexed[$key]['gross'] = $this->financial->assertCorrectionDifference($indexed[$key]['gross']);
        }

        return $indexed;
    }

    /**
     * @param  array{vat_rate: ?string, vat_code: ?string}  $identity
     * @return array{vat_rate: ?string, vat_code: ?string, net: string, vat: string, gross: string}
     */
    private function zeroGroup(array $identity): array
    {
        return [
            'vat_rate' => $identity['vat_rate'],
            'vat_code' => $identity['vat_code'],
            'net' => '0.00',
            'vat' => '0.00',
            'gross' => '0.00',
        ];
    }

    /** @param array<string, mixed> $group */
    private function isZeroGroup(array $group): bool
    {
        return $this->decimal->compare((string) $group['net'], '0.00') === 0
            && $this->decimal->compare((string) $group['vat'], '0.00') === 0
            && $this->decimal->compare((string) $group['gross'], '0.00') === 0;
    }
}
