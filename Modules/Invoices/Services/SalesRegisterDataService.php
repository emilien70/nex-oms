<?php

namespace Modules\Invoices\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;

final class SalesRegisterDataService
{
    public const BATCH_SIZE = 200;

    public function __construct(
        private readonly SalesRegisterDocumentReader $documents,
        private readonly SalesRegisterKsefNumberReader $ksef,
        private readonly SalesRegisterSummaryService $summaries,
    ) {}

    public function build(SalesRegisterFilters $filters): array
    {
        $query = Invoice::query()->whereIn('document_type', [InvoiceDocumentType::Invoice->value, InvoiceDocumentType::Correction->value])
            ->where('status', InvoiceDocumentStatus::Issued->value);
        $this->scope($query, $filters);
        $records = $warnings = [];
        $selected = 0;
        // Keyset batches avoid offset drift. Sorting the materialized result establishes the public order.
        $query->select([
            'id', 'invoice_series_id', 'series_name_snapshot', 'document_type', 'status', 'number', 'issue_date', 'sale_date',
            'buyer_snapshot', 'buyer_name_snapshot', 'buyer_tax_id_snapshot', 'seller_snapshot', 'seller_tax_id_snapshot',
            'currency', 'total_net', 'total_vat', 'total_gross', 'tax_summary_snapshot', 'tax_metadata_snapshot',
            'corrected_invoice_id', 'correction_totals_snapshot', 'order_snapshot', 'payment_snapshot',
        ])->with([
            'items:id,invoice_id,line_type,vat_rate,vat_code,total_net,total_vat,total_gross,correction_before_snapshot,correction_after_snapshot',
            'corrections' => fn ($related) => $related->where('status', InvoiceDocumentStatus::Issued->value)
                ->orderBy('issue_date')->orderBy('number')->orderBy('id')
                ->select(['id', 'corrected_invoice_id', 'number', 'issue_date']),
        ])->chunkById(self::BATCH_SIZE, function ($batch) use ($filters, &$records, &$warnings, &$selected): void {
            $ksef = $filters->includeKsef ? $this->ksef->read($batch) : [];
            foreach ($batch as $document) {
                $selected++;
                $buyer = $this->documents->buyer($document);
                if (! $this->matches($document, $buyer, $filters)) {
                    if (in_array($buyer['tax_id_state'], ['conflict', 'invalid', 'missing'], true) && $filters->taxIdPresence !== 'all') {
                        $warnings[] = $this->documents->warning($document, 'tax_id_filter_unresolved', 'selection');
                    }

                    continue;
                }
                $row = $this->documents->read($document, $buyer, $ksef[$document->id] ?? ['number' => null, 'warnings' => []]);
                $records[] = $row;
            }
        });
        usort($records, static fn (array $a, array $b): int => strcmp($a['issue_date'] ?? '', $b['issue_date'] ?? '')
            ?: strcmp($a['number'] ?? '', $b['number'] ?? '') ?: $a['id'] <=> $b['id']);
        foreach ($records as $index => &$row) {
            $row['ordinal'] = $index + 1;
            array_push($warnings, ...$row['warnings']);
        }
        unset($row);

        return [
            'selection' => ['mode' => $filters->mode, 'selected_count' => $selected, 'qualified_count' => count($records), 'record_count' => count($records)],
            'records' => $records, 'summaries' => $this->summaries->summarize($records), 'warnings' => $warnings,
        ];
    }

    private function scope(Builder $query, SalesRegisterFilters $filters): void
    {
        if ($filters->mode === 'ids') {
            $eligible = (clone $query)->whereIn('id', $filters->documentIds)->pluck('id')->all();
            $invalid = array_values(array_diff($filters->documentIds, $eligible));
            if ($invalid !== []) {
                throw new InvoiceDomainException('sales_register_documents_invalid', 'Wybrane dokumenty nie istnieją lub nie kwalifikują się do rejestru.', ['document_ids' => $invalid]);
            }
            $query->whereIn('id', $filters->documentIds);

            return;
        }
        $validSeries = InvoiceSeries::query()->whereIn('id', $filters->seriesIds)
            ->whereIn('document_type', [InvoiceDocumentType::Invoice->value, InvoiceDocumentType::Correction->value])->pluck('id')->all();
        $invalid = array_values(array_diff($filters->seriesIds, $validSeries));
        if ($invalid !== []) {
            throw new InvoiceDomainException('sales_register_series_invalid', 'Wybrane serie nie istnieją lub mają niewłaściwy typ dokumentu.', ['series_ids' => $invalid]);
        }
        $query->whereIn('invoice_series_id', $filters->seriesIds)
            ->whereDate('issue_date', '>=', $filters->issueFrom)->whereDate('issue_date', '<=', $filters->issueTo)
            ->when($filters->saleFrom !== null, fn (Builder $q) => $q->whereDate('sale_date', '>=', $filters->saleFrom))
            ->when($filters->saleTo !== null, fn (Builder $q) => $q->whereDate('sale_date', '<=', $filters->saleTo));
    }

    private function matches(Invoice $document, array $buyer, SalesRegisterFilters $filters): bool
    {
        if ($filters->currency !== null && strtoupper(trim((string) $document->currency)) !== $filters->currency) {
            return false;
        }
        if ($filters->country !== null && $buyer['country_code'] !== $filters->country) {
            return false;
        }
        if ($filters->taxIdPresence !== 'all') {
            if (in_array($buyer['tax_id_state'], ['conflict', 'invalid', 'missing'], true)) {
                return false;
            }
            if (($buyer['tax_id'] !== null) !== ($filters->taxIdPresence === 'with')) {
                return false;
            }
        }

        return true;
    }
}
