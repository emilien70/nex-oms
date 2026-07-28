<?php

namespace Modules\Invoices\Services;

use BackedEnum;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceRevision;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

class InvoiceRevisionBuilder
{
    public function create(Invoice $invoice, InvoiceOperationContext $context): InvoiceRevision
    {
        $invoice->loadMissing('items');

        return InvoiceRevision::query()->create([
            'invoice_id' => $invoice->getKey(),
            'revision_number' => $invoice->revision_number,
            'document_snapshot' => $this->documentSnapshot($invoice),
            'items_snapshot' => $invoice->items->map(fn ($item): array => [
                'order_item_id' => $item->order_item_id,
                'product_id' => $item->product_id,
                'line_type' => $this->enumValue($item->line_type),
                'position' => $item->position,
                'name' => $item->name,
                'description' => $item->description,
                'unit_name' => $item->unit_name,
                'quantity' => $item->quantity,
                'unit_price_net' => $item->unit_price_net,
                'unit_price_gross' => $item->unit_price_gross,
                'total_net' => $item->total_net,
                'total_vat' => $item->total_vat,
                'total_gross' => $item->total_gross,
                'vat_rate' => $item->vat_rate,
                'vat_code' => $item->vat_code,
                'gtu_codes' => $item->gtu_codes,
                'product_snapshot' => $item->product_snapshot,
                'metadata' => $item->metadata,
            ])->values()->all(),
            'source_snapshot_hash' => $invoice->source_snapshot_hash,
            'source' => $context->source,
            'actor_snapshot' => $context->actorSnapshot,
            'created_at' => $context->occurredAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function documentSnapshot(Invoice $invoice): array
    {
        return [
            'invoice_id' => $invoice->getKey(),
            'order_id' => $invoice->order_id,
            'invoice_series_id' => $invoice->invoice_series_id,
            'document_type' => $this->enumValue($invoice->document_type),
            'status' => $this->enumValue($invoice->status),
            'number' => $invoice->number,
            'sequence_number' => $invoice->sequence_number,
            'numbering_period_key' => $invoice->numbering_period_key,
            'number_format_snapshot' => $invoice->number_format_snapshot,
            'series_name_snapshot' => $invoice->series_name_snapshot,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'sale_date' => $invoice->sale_date?->toDateString(),
            'payment_due_date' => $invoice->payment_due_date?->toDateString(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'revision_number' => $invoice->revision_number,
            'source_snapshot_hash' => $invoice->source_snapshot_hash,
            'last_refreshed_at' => $invoice->last_refreshed_at?->toIso8601String(),
            'order_reference_snapshot' => $invoice->order_reference_snapshot,
            'seller_name_snapshot' => $invoice->seller_name_snapshot,
            'seller_tax_id_snapshot' => $invoice->seller_tax_id_snapshot,
            'buyer_name_snapshot' => $invoice->buyer_name_snapshot,
            'buyer_tax_id_snapshot' => $invoice->buyer_tax_id_snapshot,
            'recipient_name_snapshot' => $invoice->recipient_name_snapshot,
            'seller_snapshot' => $invoice->seller_snapshot,
            'buyer_snapshot' => $invoice->buyer_snapshot,
            'recipient_snapshot' => $invoice->recipient_snapshot,
            'issuer_snapshot' => $invoice->issuer_snapshot,
            'order_snapshot' => $invoice->order_snapshot,
            'payment_snapshot' => $invoice->payment_snapshot,
            'shipping_snapshot' => $invoice->shipping_snapshot,
            'series_settings_snapshot' => $invoice->series_settings_snapshot,
            'tax_summary_snapshot' => $invoice->tax_summary_snapshot,
            'tax_metadata_snapshot' => $invoice->tax_metadata_snapshot,
            'additional_information_text' => $invoice->additional_information_text,
            'currency' => $invoice->currency,
            'total_net' => $invoice->total_net,
            'total_vat' => $invoice->total_vat,
            'total_gross' => $invoice->total_gross,
            'paid_amount' => $invoice->paid_amount,
            'amount_due' => $invoice->amount_due,
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
