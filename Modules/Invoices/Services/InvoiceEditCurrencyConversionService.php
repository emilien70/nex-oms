<?php

namespace Modules\Invoices\Services;

use App\Support\CurrencyCatalog;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\ValueObjects\NbpExchangeRate;

class InvoiceEditCurrencyConversionService
{
    public function __construct(
        private readonly InvoicePdfCurrencyConversionPresenter $presenter,
        private readonly InvoiceExchangeRateReferenceDateResolver $referenceDates,
        private readonly NbpExchangeRateClient $rates,
        private readonly InvoiceCurrencyConversionService $conversion,
    ) {}

    public function assertSnapshotUsableForAnyEdit(Invoice $invoice): void
    {
        if ($this->isPln($invoice)) {
            return;
        }

        $metadata = $invoice->tax_metadata_snapshot;
        if ($metadata === null || $metadata === []) {
            return;
        }

        try {
            $this->presenter->present($invoice);
        } catch (InvoiceDomainException $exception) {
            throw $this->invalidSnapshot($exception);
        }
    }

    /** @param array<int, array<string, mixed>> $taxSummary
     * @return array<string, mixed>
     */
    public function forMoneyChange(Invoice $invoice, array $taxSummary): array
    {
        if ($this->isPln($invoice)) {
            return [];
        }

        if (($invoice->tax_metadata_snapshot ?? []) === []) {
            throw new InvoiceDomainException(
                'invoice_edit_missing_currency_snapshot',
                'Nie można zmieniać kwot historycznej Faktury walutowej bez zapisanego kursu NBP.',
            );
        }

        $this->assertSnapshotUsableForAnyEdit($invoice);

        return $this->conversion->recalculateWithHistoricalRate(
            $invoice->tax_metadata_snapshot,
            $taxSummary,
        );
    }

    public function assertMoneyChangeAllowed(Invoice $invoice): void
    {
        if ($this->isPln($invoice)) {
            return;
        }

        if (($invoice->tax_metadata_snapshot ?? []) === []) {
            throw new InvoiceDomainException(
                'invoice_edit_missing_currency_snapshot',
                'Nie można zmieniać kwot historycznej Faktury walutowej bez zapisanego kursu NBP.',
            );
        }

        $this->assertSnapshotUsableForAnyEdit($invoice);
    }

    public function referenceDateChanges(Invoice $invoice, string $issueDate, string $saleDate): bool
    {
        if ($this->isPln($invoice)) {
            return false;
        }

        $current = $this->referenceDates->resolve(
            $invoice->issue_date?->toDateString(),
            $invoice->sale_date?->toDateString(),
        );
        $candidate = $this->referenceDates->resolve($issueDate, $saleDate);

        return $current->referenceDate !== $candidate->referenceDate;
    }

    /** @return array{reference_date: ?string, rate_rule: ?string, rate: ?NbpExchangeRate} */
    public function prepareDateChange(Invoice $invoice, string $issueDate, string $saleDate): array
    {
        if ($this->isPln($invoice)) {
            return ['reference_date' => null, 'rate_rule' => null, 'rate' => null];
        }

        $metadata = $invoice->tax_metadata_snapshot ?? [];
        if ($metadata === []) {
            throw new InvoiceDomainException(
                'invoice_edit_missing_currency_snapshot',
                'Nie można zmieniać dat wpływających na kurs historycznej Faktury walutowej bez zapisanego kursu NBP.',
            );
        }

        $this->assertSnapshotUsableForAnyEdit($invoice);
        $conversion = $metadata['currency_conversion'];
        $reference = $this->referenceDates->resolve($issueDate, $saleDate);
        if ($reference->referenceDate === ($conversion['reference_date'] ?? null)) {
            return [
                'reference_date' => $reference->referenceDate,
                'rate_rule' => $reference->rateRule,
                'rate' => null,
            ];
        }

        $table = strtoupper(trim((string) ($conversion['table_type'] ?? '')));
        if (! in_array($table, ['A', 'B'], true)) {
            throw $this->invalidSnapshot();
        }

        return [
            'reference_date' => $reference->referenceDate,
            'rate_rule' => $reference->rateRule,
            'rate' => $this->rates->fetch((string) $invoice->currency, $table, $reference->referenceDate),
        ];
    }

    /** @param array<int, array<string, mixed>> $taxSummary
     * @param  array{reference_date: ?string, rate_rule: ?string, rate: ?NbpExchangeRate}  $prepared
     * @return array<string, mixed>
     */
    public function applyPreparedDateChange(Invoice $invoice, array $taxSummary, array $prepared): array
    {
        if ($this->isPln($invoice)) {
            return [];
        }

        $metadata = $invoice->tax_metadata_snapshot ?? [];
        $conversion = $metadata['currency_conversion'] ?? [];
        if (($conversion['reference_date'] ?? null) === $prepared['reference_date']) {
            return $metadata;
        }

        $rate = $prepared['rate'];
        if (! $rate instanceof NbpExchangeRate
            || $rate->referenceDate !== $prepared['reference_date']) {
            throw new InvoiceDomainException(
                'invoice_exchange_rate_context_changed',
                'Dane Faktury zmieniły się podczas przygotowywania kursu NBP. Spróbuj ponownie.',
            );
        }

        return $this->conversion->metadataForHistoricalRate(
            $taxSummary,
            $rate,
            (string) $prepared['rate_rule'],
        );
    }

    private function isPln(Invoice $invoice): bool
    {
        return strtoupper(trim((string) $invoice->currency)) === CurrencyCatalog::SYSTEM_CURRENCY;
    }

    private function invalidSnapshot(?\Throwable $previous = null): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_edit_invalid_currency_snapshot',
            'Nie można edytować Faktury, ponieważ zapisane dane kursu NBP są niekompletne lub niespójne.',
            [],
            $previous,
        );
    }
}
