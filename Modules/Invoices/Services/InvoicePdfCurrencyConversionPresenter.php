<?php

namespace Modules\Invoices\Services;

use DateTimeImmutable;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use UnexpectedValueException;

class InvoicePdfCurrencyConversionPresenter
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    /** @return array<string, mixed>|null */
    public function present(Invoice $invoice): ?array
    {
        if ((! $invoice->isInvoice() && ! $invoice->isCorrection())
            || strtoupper(trim((string) $invoice->currency)) === 'PLN') {
            return null;
        }

        return $this->presentSnapshots(
            (string) $invoice->currency,
            $invoice->tax_summary_snapshot,
            $invoice->tax_metadata_snapshot,
        );
    }

    /** @return array<string, mixed>|null */
    public function presentSnapshots(string $documentCurrency, mixed $taxSummary, mixed $metadata): ?array
    {
        $documentCurrency = strtoupper(trim($documentCurrency));
        if ($documentCurrency === 'PLN') {
            return null;
        }

        if ($metadata === null || $metadata === []) {
            return null;
        }

        if (! is_array($metadata)) {
            throw $this->invalid('Metadane przeliczenia nie są tablicą.');
        }

        $conversion = $metadata['currency_conversion'] ?? null;
        $convertedSummary = $metadata['converted_tax_summary'] ?? null;

        if (! is_array($conversion) || ! is_array($convertedSummary)) {
            throw $this->invalid('Brakuje jednej z wymaganych sekcji przeliczenia.');
        }

        $sourceCurrency = $this->currencyCode($conversion['source_currency'] ?? null, 'waluty źródłowej');
        $targetCurrency = $this->currencyCode($conversion['target_currency'] ?? null, 'waluty docelowej');
        if (($conversion['version'] ?? null) !== 1
            || ($conversion['source'] ?? null) !== 'NBP'
            || $sourceCurrency !== $documentCurrency
            || $targetCurrency !== 'PLN'
            || ! in_array($conversion['table_type'] ?? null, ['A', 'B'], true)
            || ! is_string($conversion['table_number'] ?? null)
            || trim($conversion['table_number']) === ''
            || ($conversion['rounding_mode'] ?? null) !== 'half_up'
            || ($conversion['result_scale'] ?? null) !== 2) {
            throw $this->invalid('Metadane kursu nie spełniają kontraktu wersji 1.');
        }

        $effectiveDate = $this->date($conversion['effective_date'] ?? null, 'daty publikacji');
        $referenceDate = $this->date($conversion['reference_date'] ?? null, 'daty odniesienia');
        if ($effectiveDate >= $referenceDate) {
            throw $this->invalid('Data publikacji kursu nie poprzedza daty odniesienia.');
        }

        $rate = $this->positiveDecimal($conversion['rate'] ?? null);

        if (($convertedSummary['currency'] ?? null) !== 'PLN'
            || ! is_array($convertedSummary['groups'] ?? null)) {
            throw $this->invalid('Podsumowanie PLN ma nieprawidłową walutę albo listę grup.');
        }

        $convertedTotals = [
            'net' => $this->money($convertedSummary['total_net'] ?? null, 'sumy netto'),
            'vat' => $this->money($convertedSummary['total_vat'] ?? null, 'sumy VAT'),
            'gross' => $this->money($convertedSummary['total_gross'] ?? null, 'sumy brutto'),
        ];
        $this->assertGross($convertedTotals, 'podsumowania PLN');

        $sourceGroups = $this->sourceGroups($taxSummary);
        $convertedGroups = $this->convertedGroups($convertedSummary['groups']);
        $this->assertSummarySums($convertedGroups, $convertedTotals);

        $pairs = [];
        foreach ($sourceGroups as $key => $source) {
            if (! array_key_exists($key, $convertedGroups)) {
                throw $this->invalid("Brak grupy PLN dla {$key}.");
            }

            $converted = $convertedGroups[$key];
            $converted['vat'] = $source['vat'];
            $pairs[] = [
                'source' => $source,
                'converted' => $converted,
            ];
            unset($convertedGroups[$key]);
        }

        if ($convertedGroups !== []) {
            throw $this->invalid('Podsumowanie PLN zawiera dodatkową grupę podatkową.');
        }

        $tableNumber = trim($conversion['table_number']);

        return [
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'rate' => $rate,
            'rate_text' => "1 {$sourceCurrency} = {$rate} {$targetCurrency}",
            'effective_date' => $effectiveDate,
            'table_number' => $tableNumber,
            'totals' => $convertedTotals,
            'tax_rows' => array_values(array_map(
                static fn (array $pair): array => $pair['converted'],
                $pairs,
            )),
            'tax_row_pairs' => $pairs,
        ];
    }

    /** @return array<string, array<string, ?string>> */
    private function sourceGroups(mixed $groups): array
    {
        if (! is_array($groups)) {
            throw $this->invalid('Źródłowe grupy podatkowe nie są tablicą.');
        }

        $indexed = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                throw $this->invalid('Źródłowa grupa podatkowa nie jest tablicą.');
            }

            [$key, $label, $rate, $code] = $this->taxIdentity($group);
            if (array_key_exists($key, $indexed)) {
                throw $this->invalid("Powtórzony identyfikator źródłowej grupy {$key}.");
            }

            $indexed[$key] = [
                'vat' => $label,
                'vat_rate' => $rate,
                'vat_code' => $code,
                'net' => $this->money($group['net'] ?? null, 'netto grupy źródłowej'),
                'vat_amount' => $this->money($group['vat'] ?? null, 'VAT grupy źródłowej'),
                'gross' => $this->money($group['gross'] ?? null, 'brutto grupy źródłowej'),
            ];
        }

        return $indexed;
    }

    /** @param array<int, mixed> $groups
     * @return array<string, array<string, ?string>>
     */
    private function convertedGroups(array $groups): array
    {
        $indexed = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                throw $this->invalid('Grupa podatkowa PLN nie jest tablicą.');
            }

            [$key, $label, $rate, $code] = $this->taxIdentity($group);
            if (array_key_exists($key, $indexed)) {
                throw $this->invalid("Powtórzony identyfikator grupy PLN {$key}.");
            }

            $row = [
                'vat' => $label,
                'vat_rate' => $rate,
                'vat_code' => $code,
                'net' => $this->money($group['net'] ?? null, 'netto grupy PLN'),
                'vat_amount' => $this->money($group['vat'] ?? null, 'VAT grupy PLN'),
                'gross' => $this->money($group['gross'] ?? null, 'brutto grupy PLN'),
            ];
            $this->assertGross($row, "grupy {$key}");
            $indexed[$key] = $row;
        }

        return $indexed;
    }

    /** @param array<string, mixed> $group
     * @return array{string, string, ?string, ?string}
     */
    private function taxIdentity(array $group): array
    {
        $rawCode = $group['vat_code'] ?? null;
        if ($rawCode !== null && trim((string) $rawCode) !== '') {
            if (! is_string($rawCode)) {
                throw $this->invalid('Kod VAT nie jest tekstem.');
            }

            $code = strtoupper(trim($rawCode));
            if (! preg_match('/^[A-Z0-9_]+$/', $code)) {
                throw $this->invalid('Kod VAT ma nieprawidłowy format.');
            }

            return ['code:'.strtolower($code), $code, null, $code];
        }

        $rawRate = $group['vat_rate'] ?? null;
        if (! is_string($rawRate) || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $rawRate)) {
            throw $this->invalid('Stawka VAT ma nieprawidłowy format.');
        }

        $rate = $this->normalize($rawRate, 2);
        $label = rtrim(rtrim($rate, '0'), '.').'%';

        return ['rate:'.$rate, $label, $rate, null];
    }

    /** @param array<string, ?string> $row */
    private function assertGross(array $row, string $context): void
    {
        if ($this->add((string) $row['net'], (string) ($row['vat_amount'] ?? $row['vat'])) !== $row['gross']) {
            throw $this->invalid("Kwota brutto {$context} nie jest sumą netto i VAT.");
        }
    }

    /** @param array<string, array<string, ?string>> $groups
     * @param  array<string, string>  $totals
     */
    private function assertSummarySums(array $groups, array $totals): void
    {
        $sums = ['net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];
        foreach ($groups as $group) {
            $sums['net'] = $this->add($sums['net'], (string) $group['net']);
            $sums['vat'] = $this->add($sums['vat'], (string) $group['vat_amount']);
            $sums['gross'] = $this->add($sums['gross'], (string) $group['gross']);
        }

        if ($sums !== $totals) {
            throw $this->invalid('Sumy podsumowania PLN nie odpowiadają sumom grup podatkowych.');
        }
    }

    private function currencyCode(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Z]{3}$/', $value)) {
            throw $this->invalid("Nieprawidłowy kod {$field}.");
        }

        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw $this->invalid("Brak poprawnej {$field}.");
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw $this->invalid("Nieprawidłowy format {$field}.");
        }

        return $value;
    }

    private function positiveDecimal(mixed $value): string
    {
        if (! is_string($value)
            || ! preg_match('/^\d+(?:\.\d+)?$/', $value)
            || ! preg_match('/[1-9]/', $value)) {
            throw $this->invalid('Kurs ma nieprawidłowy format albo nie jest dodatni.');
        }

        return $value;
    }

    private function money(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^-?\d+\.\d{2}$/', $value)) {
            throw $this->invalid("Nieprawidłowy format {$field}.");
        }

        return $value;
    }

    private function add(string $left, string $right): string
    {
        try {
            return $this->decimal->add($left, $right);
        } catch (InvoiceDomainException $exception) {
            throw $this->invalid('Kwoty przeliczenia wykraczają poza obsługiwany zakres.');
        }
    }

    private function normalize(string $value, int $scale): string
    {
        try {
            return $this->decimal->normalize($value, $scale);
        } catch (InvoiceDomainException $exception) {
            throw $this->invalid('Stawka VAT wykracza poza obsługiwany zakres.');
        }
    }

    private function invalid(string $reason): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_pdf_invalid_currency_conversion_snapshot',
            'Nie można wygenerować PDF, ponieważ zapisane dane przeliczenia walutowego są niekompletne.',
            [],
            new UnexpectedValueException($reason),
        );
    }
}
