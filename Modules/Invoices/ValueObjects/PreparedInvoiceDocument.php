<?php

namespace Modules\Invoices\ValueObjects;

final readonly class PreparedInvoiceDocument
{
    /**
     * @param  array<string, mixed>  $invoiceAttributes
     * @param  array<int, array<string, mixed>>  $itemAttributes
     * @param  array<string, mixed>  $hashPayload
     */
    public function __construct(
        public array $invoiceAttributes,
        public array $itemAttributes,
        public array $hashPayload,
    ) {}

    /** @param array<string, mixed> $taxMetadataSnapshot */
    public function withTaxMetadataSnapshot(array $taxMetadataSnapshot): self
    {
        return new self(
            invoiceAttributes: array_replace($this->invoiceAttributes, [
                'tax_metadata_snapshot' => $taxMetadataSnapshot,
            ]),
            itemAttributes: $this->itemAttributes,
            hashPayload: $this->hashPayload,
        );
    }
}
