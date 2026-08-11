<?php

namespace Modules\Invoices\Services;

use App\Support\CurrencyCatalog;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\ValueObjects\InvoiceCurrencyConversionContext;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Modules\Invoices\ValueObjects\PreparedInvoiceDocument;
use Throwable;

class InvoiceCurrencyConversionService
{
    public function __construct(
        private readonly CurrencyCatalog $currencies,
        private readonly InvoiceExchangeRateReferenceDateResolver $referenceDateResolver,
        private readonly NbpExchangeRateClient $rates,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
    ) {}

    public function contextFor(PreparedInvoiceDocument $prepared): InvoiceCurrencyConversionContext
    {
        $attributes = $prepared->invoiceAttributes;
        $currency = $this->currencies->normalize($attributes['currency'] ?? null);
        $issueDate = is_string($attributes['issue_date'] ?? null) ? $attributes['issue_date'] : '';
        $saleDate = is_string($attributes['sale_date'] ?? null) ? $attributes['sale_date'] : '';

        if ($currency === null) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_currency_unknown',
                'Nie można wystawić Faktury, ponieważ waluta dokumentu jest nieprawidłowa.',
            );
        }

        if ($currency === CurrencyCatalog::SYSTEM_CURRENCY) {
            return new InvoiceCurrencyConversionContext(
                currency: $currency,
                issueDate: $issueDate,
                saleDate: $saleDate,
                referenceDate: null,
                rateRule: null,
                nbpTable: null,
            );
        }

        $currencyModel = $this->currencies->find($currency);
        if ($currencyModel === null) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_currency_unknown',
                "Nie można wystawić Faktury, ponieważ waluta {$currency} nie istnieje w katalogu walut.",
            );
        }

        $table = strtoupper(trim((string) $currencyModel->nbp_table));
        if (! in_array($table, ['A', 'B'], true)) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_table_missing',
                "Nie można ustalić tabeli NBP dla waluty {$currency}. Uruchom ręczną synchronizację katalogu walut.",
            );
        }

        $reference = $this->referenceDateResolver->resolve($issueDate, $saleDate);

        return new InvoiceCurrencyConversionContext(
            currency: $currency,
            issueDate: $issueDate,
            saleDate: $saleDate,
            referenceDate: $reference->referenceDate,
            rateRule: $reference->rateRule,
            nbpTable: $table,
        );
    }

    public function fetchRate(InvoiceCurrencyConversionContext $context): ?NbpExchangeRate
    {
        if (! $context->requiresConversion()) {
            return null;
        }

        return $this->rates->fetch(
            $context->currency,
            (string) $context->nbpTable,
            (string) $context->referenceDate,
        );
    }

    public function apply(
        PreparedInvoiceDocument $prepared,
        InvoiceCurrencyConversionContext $context,
        ?NbpExchangeRate $rate,
    ): PreparedInvoiceDocument {
        if (! $this->contextFor($prepared)->equals($context)) {
            throw $this->contextChanged();
        }

        if (! $context->requiresConversion()) {
            return $prepared->withTaxMetadataSnapshot([]);
        }

        if ($rate === null
            || $rate->currencyCode !== $context->currency
            || $rate->tableType !== $context->nbpTable
            || $rate->referenceDate !== $context->referenceDate) {
            throw $this->contextChanged();
        }

        try {
            $converted = $this->convertTaxSummary(
                $prepared->invoiceAttributes['tax_summary_snapshot'] ?? null,
                $rate->rate,
            );
        } catch (InvoiceDomainException $exception) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_calculation_failed',
                'Nie można przeliczyć podsumowania podatkowego Faktury do PLN.',
                [],
                $exception,
            );
        } catch (Throwable $exception) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_calculation_failed',
                'Nie można przeliczyć podsumowania podatkowego Faktury do PLN.',
                [],
                $exception,
            );
        }

        return $prepared->withTaxMetadataSnapshot([
            'currency_conversion' => [
                'version' => 1,
                'source' => $rate->source,
                'source_currency' => $rate->currencyCode,
                'target_currency' => CurrencyCatalog::SYSTEM_CURRENCY,
                'table_type' => $rate->tableType,
                'table_number' => $rate->tableNumber,
                'effective_date' => $rate->effectiveDate,
                'reference_date' => $rate->referenceDate,
                'rate' => $rate->rate,
                'rate_rule' => $context->rateRule,
                'rounding_mode' => 'half_up',
                'result_scale' => 2,
            ],
            'converted_tax_summary' => $converted,
        ]);
    }

    public function contextChanged(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_exchange_rate_context_changed',
            'Dane zamówienia zmieniły się podczas przygotowywania Faktury. Spróbuj ponownie.',
        );
    }

    /**
     * Rebuilds only the converted PLN summary while preserving the historical rate snapshot.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $taxSummary
     * @return array<string, mixed>
     */
    public function recalculateWithHistoricalRate(array $metadata, array $taxSummary): array
    {
        $conversion = $metadata['currency_conversion'] ?? null;
        if (! is_array($conversion) || ! is_string($conversion['rate'] ?? null)) {
            throw new InvoiceDomainException(
                'invoice_edit_invalid_currency_snapshot',
                'Nie można edytować Faktury, ponieważ zapisane dane kursu NBP są niekompletne.',
            );
        }

        $metadata['converted_tax_summary'] = $this->convertTaxSummary($taxSummary, $conversion['rate']);

        return $metadata;
    }

    /**
     * @param  array<int, array<string, mixed>>  $taxSummary
     * @return array<string, mixed>
     */
    public function metadataForHistoricalRate(
        array $taxSummary,
        NbpExchangeRate $rate,
        string $rateRule,
    ): array {
        return [
            'currency_conversion' => [
                'version' => 1,
                'source' => $rate->source,
                'source_currency' => $rate->currencyCode,
                'target_currency' => CurrencyCatalog::SYSTEM_CURRENCY,
                'table_type' => $rate->tableType,
                'table_number' => $rate->tableNumber,
                'effective_date' => $rate->effectiveDate,
                'reference_date' => $rate->referenceDate,
                'rate' => $rate->rate,
                'rate_rule' => $rateRule,
                'rounding_mode' => 'half_up',
                'result_scale' => 2,
            ],
            'converted_tax_summary' => $this->convertTaxSummary($taxSummary, $rate->rate),
        ];
    }

    /** @return array<string, mixed> */
    private function convertTaxSummary(mixed $summary, string $rate): array
    {
        if (! is_array($summary)) {
            throw new InvoiceDomainException('invoice_exchange_rate_calculation_failed', 'Nieprawidłowe podsumowanie podatkowe.');
        }

        $groups = [];
        $totalNet = '0.00';
        $totalVat = '0.00';
        $totalGross = '0.00';

        foreach ($summary as $group) {
            if (! is_array($group)
                || ! array_key_exists('net', $group)
                || ! array_key_exists('vat', $group)) {
                throw new InvoiceDomainException('invoice_exchange_rate_calculation_failed', 'Nieprawidłowa grupa podatkowa.');
            }

            $net = $this->decimal->multiplyAndRound((string) $group['net'], $rate, 2);
            $vat = $this->decimal->multiplyAndRound((string) $group['vat'], $rate, 2);
            $gross = $this->decimal->add($net, $vat, 2);
            $identity = $this->taxIdentity->normalize(
                $group['vat_rate'] ?? null,
                $group['vat_code'] ?? null,
            );
            if ($this->taxIdentity->key($identity) === null) {
                throw new InvoiceDomainException('invoice_exchange_rate_calculation_failed', 'Nieprawidłowa grupa podatkowa.');
            }

            $groups[] = [
                ...$identity,
                'net' => $net,
                'vat' => $vat,
                'gross' => $gross,
            ];
            $totalNet = $this->decimal->add($totalNet, $net, 2);
            $totalVat = $this->decimal->add($totalVat, $vat, 2);
            $totalGross = $this->decimal->add($totalGross, $gross, 2);
        }

        return [
            'currency' => CurrencyCatalog::SYSTEM_CURRENCY,
            'groups' => $groups,
            'total_net' => $totalNet,
            'total_vat' => $totalVat,
            'total_gross' => $totalGross,
        ];
    }
}
