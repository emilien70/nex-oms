<?php

namespace Modules\Invoices\Services;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;

class CorrectionStateComparator
{
    private const DOCUMENT_FIELDS = [
        'issue_date',
        'sale_date',
        'correction_reason',
        'correction_totals_snapshot',
        'buyer_name_snapshot',
        'buyer_tax_id_snapshot',
        'buyer_snapshot',
        'issuer_snapshot',
        'payment_snapshot',
        'tax_summary_snapshot',
        'tax_metadata_snapshot',
        'additional_information_text',
        'total_net',
        'total_vat',
        'total_gross',
        'amount_due',
    ];

    private const ITEM_FIELDS = [
        'order_item_id',
        'product_id',
        'source_invoice_item_id',
        'line_type',
        'position',
        'name',
        'description',
        'unit_name',
        'quantity',
        'unit_price_net',
        'unit_price_gross',
        'total_net',
        'total_vat',
        'total_gross',
        'vat_rate',
        'vat_code',
        'gtu_codes',
        'product_snapshot',
        'metadata',
        'correction_before_snapshot',
        'correction_after_snapshot',
        'correction_difference_snapshot',
    ];

    private const DECIMAL_SCALES = [
        'quantity' => 4,
        'unit_price_net' => 4,
        'unit_price_gross' => 4,
        'total_net' => 2,
        'total_vat' => 2,
        'total_gross' => 2,
        'vat_rate' => 2,
        'amount_due' => 2,
        'net' => 2,
        'vat' => 2,
        'gross' => 2,
    ];

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    /**
     * @param  array<string, mixed>  $documentCandidate
     * @param  array<int, array<string, mixed>>  $itemCandidates
     */
    public function matches(
        Invoice $correction,
        array $documentCandidate,
        array $itemCandidates,
    ): bool {
        $currentDocument = [];
        foreach (self::DOCUMENT_FIELDS as $field) {
            $currentDocument[$field] = $correction->getAttribute($field);
        }

        return $this->canonicalize($currentDocument) === $this->canonicalize($documentCandidate)
            && $this->canonicalize($this->currentItems($correction)) === $this->canonicalize($itemCandidates);
    }

    /** @return array<int, array<string, mixed>> */
    private function currentItems(Invoice $correction): array
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = $correction->relationLoaded('items')
            ? $correction->getRelation('items')
            : $correction->items()->get();

        return $items->map(function (InvoiceItem $item): array {
            $attributes = [];
            foreach (self::ITEM_FIELDS as $field) {
                $attributes[$field] = $item->getAttribute($field);
            }

            return $attributes;
        })->all();
    }

    private function canonicalize(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->canonicalize(
                    $childValue,
                    is_string($childKey) ? $childKey : null,
                );
            }

            return $value;
        }

        if ($value !== null && $key !== null && isset(self::DECIMAL_SCALES[$key])) {
            return $this->decimal->normalize((string) $value, self::DECIMAL_SCALES[$key]);
        }

        if ($value !== null && $key !== null && ($key === 'position' || str_ends_with($key, '_id'))) {
            return (int) $value;
        }

        return $value;
    }
}
