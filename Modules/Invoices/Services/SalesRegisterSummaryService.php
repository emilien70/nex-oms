<?php

namespace Modules\Invoices\Services;

final class SalesRegisterSummaryService
{
    public function __construct(private readonly SalesRegisterValues $values) {}

    public function summarize(array $records): array
    {
        $currencies = $countries = $rates = [];
        $pln = $foreignPln = [];
        foreach ($records as $record) {
            $currency = $record['currency'] ?? 'unknown';
            $country = $record['buyer']['country_code'] ?? 'unknown';
            $currencies[$currency][] = $record;
            $countries[$country][$currency][] = $record;
            $pln[] = $record;
            if ($record['currency'] !== null && $record['currency'] !== 'PLN') {
                $foreignPln[] = $record;
            }
            if ($record['exchange_rate'] !== null) {
                $rates[] = ['document_id' => $record['id'], 'number' => $record['number']] + $record['exchange_rate'];
            }
        }
        ksort($currencies);
        ksort($countries);
        foreach ($currencies as $currency => $rows) {
            $currencies[$currency] = $this->bucket($rows, false);
        }
        foreach ($countries as $country => $byCurrency) {
            ksort($byCurrency);
            $label = $country === 'unknown' ? 'Nieustalony kraj' : $country;
            foreach ($byCurrency as $currency => $rows) {
                $byCurrency[$currency] = $this->bucket($rows, false);
            }
            $countries[$country] = ['label' => $label, 'currencies' => $byCurrency];
        }

        return [
            'currencies' => $currencies, 'countries' => $countries,
            'foreign_in_pln' => $this->bucket($foreignPln, true), 'combined_pln' => $this->bucket($pln, true),
            'exchange_rates' => $rates,
            'completeness' => [
                'original' => $this->coverage($records, 'original'), 'vat' => $this->coverage($records, 'vat'),
                'shipping' => $this->coverage($records, 'shipping'), 'pln' => $this->coverage($records, 'pln'),
                'pln_vat' => $this->coverage($records, 'pln_vat'), 'shipping_pln' => $this->coverage($records, 'shipping_pln'),
                'country' => $this->coverage($records, 'country'),
            ],
        ];
    }

    private function bucket(array $records, bool $pln): array
    {
        $amounts = $taxGroups = $shippingAmounts = $shippingGroups = [];
        foreach ($records as $record) {
            if ($record['completeness'][$pln ? 'pln' : 'original']) {
                $amounts[] = $pln ? $record['pln']['totals'] : $record['totals'];
            }
            if ($record['completeness'][$pln ? 'pln_vat' : 'vat']) {
                array_push($taxGroups, ...($pln ? $record['pln']['groups'] : $record['vat_groups']));
            }
            if ($record['completeness'][$pln ? 'shipping_pln' : 'shipping']) {
                $shipping = $pln ? $record['shipping_pln'] : $record['shipping'];
                $shippingAmounts[] = $shipping['totals'];
                array_push($shippingGroups, ...$shipping['groups']);
            }
        }

        return [
            'document_count' => count($records),
            'totals' => $amounts === [] && $records !== [] ? null : $this->values->sum($amounts),
            'vat_groups' => $this->aggregateGroups($taxGroups),
            'shipping' => [
                'totals' => $shippingAmounts === [] && $records !== [] ? null : $this->values->sum($shippingAmounts),
                'vat_groups' => $this->aggregateGroups($shippingGroups),
            ],
            'coverage' => [
                'totals' => $this->coverage($records, $pln ? 'pln' : 'original'),
                'vat' => $this->coverage($records, $pln ? 'pln_vat' : 'vat'),
                'shipping' => $this->coverage($records, $pln ? 'shipping_pln' : 'shipping'),
            ],
        ];
    }

    private function aggregateGroups(array $groups): array
    {
        $aggregated = [];
        foreach ($groups as $group) {
            $key = $group['key'];
            $amounts = $this->values->sum([$aggregated[$key] ?? $this->values->zero(), $group]);
            $aggregated[$key] = array_replace($group, $amounts);
        }

        return $this->values->sortGroups($aggregated);
    }

    private function coverage(array $records, string $section): array
    {
        $included = $excluded = [];
        foreach ($records as $record) {
            if ($record['completeness'][$section]) {
                $included[] = $record['id'];
            } else {
                $excluded[] = $record['id'];
            }
        }

        return [
            'complete' => $excluded === [], 'included_count' => count($included), 'excluded_count' => count($excluded),
            'included_ids' => $included, 'excluded_ids' => $excluded,
        ];
    }
}
