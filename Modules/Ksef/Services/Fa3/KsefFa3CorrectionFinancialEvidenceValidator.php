<?php

namespace Modules\Ksef\Services\Fa3;

use InvalidArgumentException;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Ksef\Exceptions\KsefApiException;

final class KsefFa3CorrectionFinancialEvidenceValidator
{
    public function __construct(private readonly InvoiceDecimalCalculator $decimal) {}

    public function validate(?array $evidence): void
    {
        try {
            $this->assertValid($evidence);
        } catch (InvalidArgumentException|InvoiceDomainException) {
            throw new KsefApiException(
                'Zamrożone dane finansowe Korekty Offline są niekompletne lub niespójne.',
                'ksef_offline_presentation_integrity_invalid',
            );
        }
    }

    private function assertValid(?array $evidence): void
    {
        $this->keys($evidence, ['version', 'profile', 'currency', 'lines', 'tax_buckets', 'totals']);
        if ($evidence['version'] !== 1 || $evidence['profile'] !== 'correction_financial'
            || ! is_string($evidence['currency']) || preg_match('/^[A-Z]{3}$/D', $evidence['currency']) !== 1
            || ! is_array($evidence['lines']) || ! array_is_list($evidence['lines'])) {
            throw new InvalidArgumentException;
        }
        $this->keys($evidence['tax_buckets'], array_keys(KsefFa3CorrectionTaxBuckets::FIELDS));
        $this->keys($evidence['totals'], ['net', 'vat', 'gross']);
        $expected = array_fill_keys(array_keys(KsefFa3CorrectionTaxBuckets::FIELDS), ['net' => '0.00', 'vat' => '0.00']);
        $totals = ['net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];
        $used = [];
        $previousPosition = 0;
        foreach ($evidence['lines'] as $line) {
            $this->keys($line, ['position', 'before', 'after']);
            if (! is_int($line['position']) || $line['position'] <= $previousPosition) {
                throw new InvalidArgumentException;
            }
            $previousPosition = $line['position'];
            foreach (['before', 'after'] as $side) {
                $values = $line[$side];
                $this->keys($values, ['fa3_rate', 'total_net', 'total_vat', 'total_gross']);
                if (! is_string($values['fa3_rate'])) {
                    throw new InvalidArgumentException;
                }
                $bucket = KsefFa3CorrectionTaxBuckets::forRate($values['fa3_rate']);
                $used[$bucket] = true;
                foreach (['net', 'vat', 'gross'] as $field) {
                    $this->money($values['total_'.$field]);
                    if ($this->decimal->compare($values['total_'.$field], '0.00') < 0) {
                        throw new InvalidArgumentException;
                    }
                    $operation = $side === 'before' ? 'subtract' : 'add';
                    $totals[$field] = $this->decimal->{$operation}($totals[$field], $values['total_'.$field]);
                    if ($field !== 'gross') {
                        $expected[$bucket][$field] = $this->decimal->{$operation}($expected[$bucket][$field], $values['total_'.$field]);
                    }
                }
                if ($this->decimal->add($values['total_net'], $values['total_vat']) !== $values['total_gross']
                    || (! str_starts_with($bucket, 'standard_') && $values['total_vat'] !== '0.00')) {
                    throw new InvalidArgumentException;
                }
            }
        }
        foreach ($expected as $key => $amounts) {
            $stored = $evidence['tax_buckets'][$key];
            $zero = $amounts === ['net' => '0.00', 'vat' => '0.00'];
            if ($stored === null) {
                if (! $zero) {
                    throw new InvalidArgumentException;
                }

                continue;
            }
            $hasPlnVat = is_array($stored) && array_key_exists('pln_vat', $stored);
            $this->keys($stored, $hasPlnVat ? ['net', 'vat', 'pln_vat'] : ['net', 'vat']);
            foreach ($stored as $amount) {
                $this->money($amount);
            }
            if (! isset($used[$key]) || $stored['net'] !== $amounts['net'] || $stored['vat'] !== $amounts['vat']
                || ($hasPlnVat && ($evidence['currency'] === 'PLN' || ! str_starts_with($key, 'standard_')))
                || ($zero && (! $hasPlnVat || $stored['pln_vat'] === '0.00'))) {
                throw new InvalidArgumentException;
            }
        }
        foreach ($totals as $field => $amount) {
            $this->money($evidence['totals'][$field]);
            if ($evidence['totals'][$field] !== $amount) {
                throw new InvalidArgumentException;
            }
        }
        if ($this->decimal->add($totals['net'], $totals['vat']) !== $totals['gross']) {
            throw new InvalidArgumentException;
        }
    }

    private function keys(mixed $value, array $expected): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException;
        }
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException;
        }
    }

    private function money(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]{0,17})\.[0-9]{2}$/D', $value) !== 1
            || $value === '-0.00') {
            throw new InvalidArgumentException;
        }
    }
}
