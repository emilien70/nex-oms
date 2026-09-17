<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Services\KsefFa3TaxTreatmentResolver;

/** Maps only persisted ordinary-sales semantics, never initializes KSeF snapshots. */
final class JpkV7m3TaxMapper
{
    public function __construct(private readonly SalesRegisterValues $values, private readonly InvoiceDecimalCalculator $decimal,
        private readonly KsefFa3TaxTreatmentResolver $semantics) {}

    public function map(array $record, Invoice $document): array
    {
        $id = $record['id'];
        $metadata = $document->tax_metadata_snapshot;
        $correction = $record['type'] === 'correction';
        $tax = data_get($metadata, $correction ? 'ksef_correction' : 'ksef_tax');
        if (! is_array($tax) || ($tax['version'] ?? null) !== 1 || ($tax['profile'] ?? null) !== ($correction ? 'correction' : 'ordinary')
            || ! is_array($tax['line_treatments'] ?? null) || ! array_is_list($tax['line_treatments'])) {
            JpkV7m3Context::fail($id, 'klasyfikacja VAT', 'Brak utrwalonej, wersjonowanej klasyfikacji pozycji.');
        }
        foreach (['jpk_procedures', 'procedures', 'document_type', 'oss', 'margin_scheme', 'reverse_charge', 'bad_debt_relief'] as $key) {
            if (! empty($metadata[$key])) {
                JpkV7m3Context::fail($id, $key, 'Procedura szczególna nie jest obsługiwana w tym profilu.');
            }
        }
        $annotations = data_get($metadata, 'ksef_tax.annotations', []);
        if (! is_array($annotations)) {
            JpkV7m3Context::fail($id, 'annotations', 'Nieprawidłowy snapshot oznaczeń.');
        }
        foreach ($annotations as $key => $value) {
            if ($key !== 'split_payment' && $value !== false && $value !== null) {
                JpkV7m3Context::fail($id, 'annotations.'.$key, 'Procedura szczególna wymaga odrębnego mapowania JPK.');
            }
        }
        if ($record['totals'] === null || $record['vat_groups'] === null || $record['pln'] === null || $record['pln']['groups'] === null) {
            JpkV7m3Context::fail($id, 'kwoty PLN', 'Brak spójnych kwot, grup VAT lub historycznego przeliczenia PLN.');
        }
        if ($correction) {
            foreach ($record['warnings'] as $warning) {
                if (str_contains($warning['code'], 'correction_') && str_contains($warning['code'], '_missing')) {
                    JpkV7m3Context::fail($id, 'różnica Korekty', 'Brak kompletnej zapisanej różnicy.');
                }
            }
        }
        $entries = [];
        foreach ($tax['line_treatments'] as $entry) {
            if (! is_array($entry) || ! is_int($entry['invoice_item_id'] ?? null) || isset($entries[$entry['invoice_item_id']])) {
                JpkV7m3Context::fail($id, 'line_treatments', 'Nieprawidłowe lub powtórzone powiązanie pozycji.');
            }
            $entries[$entry['invoice_item_id']] = $entry;
        }
        if (count($entries) !== $document->items->count() || $entries === []) {
            JpkV7m3Context::fail($id, 'line_treatments', 'Klasyfikacja nie obejmuje dokładnie wszystkich pozycji.');
        }
        $groups = $categories = $amounts = $gtu = [];
        foreach ($document->items as $item) {
            $entry = $entries[$item->id] ?? null;
            if ($entry === null || ($entry['position'] ?? null) !== $item->position
                || ($correction && ($entry['source_invoice_item_id'] ?? null) !== $item->source_invoice_item_id)) {
                JpkV7m3Context::fail($id, 'line_treatments', 'Klasyfikacja nie odpowiada zapisanej pozycji.');
            }
            $codes = $item->gtu_codes;
            if (! is_array($codes) || ! array_is_list($codes)) {
                JpkV7m3Context::fail($id, 'GTU', 'Nieprawidłowy snapshot GTU.');
            }
            foreach ($codes as $code) {
                if (! is_string($code) || ! preg_match('/^GTU_(0[1-9]|1[0-3])$/D', $code)) {
                    JpkV7m3Context::fail($id, 'GTU', 'Nieobsługiwane oznaczenie GTU.');
                }
                $gtu[$code] = '1';
            }
            $states = $correction ? [[$item->correction_before_snapshot, $entry['before'] ?? null, -1], [$item->correction_after_snapshot, $entry['after'] ?? null, 1]]
                : [[$item->getAttributes(), array_diff_key($entry, array_flip(['invoice_item_id', 'position'])), 1]];
            foreach ($states as [$state, $meaning, $sign]) {
                if (! is_array($state) || ! is_array($meaning) || ($meaning['status'] ?? null) !== 'resolved'
                    || ($totals = $this->values->totals($state, 'total_')) === null || ($identity = $this->values->identity($state)) === null
                    || ! $this->canonical($state, $meaning)) {
                    JpkV7m3Context::fail($id, 'pozycja '.$item->id, 'Brak spójnego stanu i jednoznacznej klasyfikacji VAT.');
                }
                $fields = match ($meaning['treatment']) {
                    'standard' => match ($identity['vat_rate']) {
                        '23.00', '22.00' => ['K_19', 'K_20'], '8.00', '7.00' => ['K_17', 'K_18'], '5.00' => ['K_15', 'K_16'],
                        default => JpkV7m3Context::fail($id, 'VAT', 'Nieobsługiwana stawka krajowa.'),
                    },
                    'domestic_zero' => ['K_13'], 'wdt' => ['K_21'], 'export' => ['K_22'],
                    default => JpkV7m3Context::fail($id, 'VAT', 'Nieobsługiwana klasyfikacja podatkowa.'),
                };
                if (count($fields) === 1 && $totals['vat'] !== '0.00') {
                    JpkV7m3Context::fail($id, 'VAT', 'Niezgodny podatek przy stawce zerowej.');
                }
                $key = $identity['key'];
                $categories[$key][implode(',', $fields)] = $fields;
                $groups[$key] ??= $identity + $this->values->zero();
                foreach ($totals as $field => $value) {
                    $groups[$key][$field] = $this->add($groups[$key][$field], $value, $sign);
                }
                if ($record['currency'] === 'PLN') {
                    $amounts[$fields[0]] = $this->add($amounts[$fields[0]] ?? '0.00', $totals['net'], $sign);
                    if (isset($fields[1])) {
                        $amounts[$fields[1]] = $this->add($amounts[$fields[1]] ?? '0.00', $totals['vat'], $sign);
                    }
                }
            }
        }
        // Reconcile persisted line states against the common report, ignoring absent zero-delta groups only.
        $expected = array_column($record['vat_groups'], null, 'key');
        foreach (array_unique([...array_keys($groups), ...array_keys($expected)]) as $key) {
            if (($this->values->totals($groups[$key] ?? []) ?? $this->values->zero()) !== ($this->values->totals($expected[$key] ?? []) ?? $this->values->zero())) {
                JpkV7m3Context::fail($id, 'grupy VAT', 'Kwoty pozycji nie odpowiadają zapisanym grupom dokumentu.');
            }
        }
        if ($record['currency'] !== 'PLN') {
            foreach ($categories as $options) {
                if (count($options) !== 1) {
                    JpkV7m3Context::fail($id, 'grupy PLN', 'Zapisane przeliczenie nie rozdziela kategorii JPK o tej samej tożsamości VAT.');
                }
            }
            foreach ($record['pln']['groups'] as $group) {
                $options = $categories[$group['key']] ?? [];
                if (count($options) !== 1) {
                    JpkV7m3Context::fail($id, 'grupy PLN', 'Brak jednoznacznego powiązania grupy z kategorią JPK.');
                }
                $fields = reset($options);
                $amounts[$fields[0]] = $this->decimal->add($amounts[$fields[0]] ?? '0.00', $group['net']);
                if (isset($fields[1])) {
                    $amounts[$fields[1]] = $this->decimal->add($amounts[$fields[1]] ?? '0.00', $group['vat']);
                }
            }
        }
        ksort($gtu);
        uksort($amounts, static fn ($a, $b) => (int) substr($a, 2) <=> (int) substr($b, 2));

        return $gtu + $amounts;
    }

    private function add(string $left, string $right, int $sign): string
    {
        return $sign === 1 ? $this->decimal->add($left, $right) : $this->decimal->subtract($left, $right);
    }

    private function canonical(array $state, array $meaning): bool
    {
        try {
            return $this->semantics->isCanonicalSnapshot($state['vat_rate'] ?? null, $state['vat_code'] ?? null, $meaning);
        } catch (InvoiceDomainException) {
            return false;
        }
    }
}
