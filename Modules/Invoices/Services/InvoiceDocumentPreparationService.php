<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;
use Modules\Invoices\ValueObjects\PreparedInvoiceDocument;

class InvoiceDocumentPreparationService
{
    public function __construct(
        private readonly InvoiceDateResolver $dateResolver,
        private readonly AdditionalInformationRenderer $informationRenderer,
        private readonly InvoiceItemBuilder $itemBuilder,
        private readonly InvoiceTotalsCalculator $totalsCalculator,
        private readonly InvoiceSnapshotBuilder $snapshotBuilder,
    ) {}

    public function forCreation(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
    ): PreparedInvoiceDocument {
        return $this->prepare(
            $order,
            $series,
            $context,
            $this->dateResolver->forCreation($order, $series, $context),
        );
    }

    public function forRefresh(
        Order $order,
        InvoiceSeries $series,
        Invoice $invoice,
        InvoiceOperationContext $context,
    ): PreparedInvoiceDocument {
        return $this->prepare(
            $order,
            $series,
            $context,
            $this->dateResolver->forRefresh($order, $series, $invoice),
        );
    }

    /**
     * @param  array{issue_date: string, sale_date: string, payment_due_date: ?string, issued_at: mixed}  $dates
     */
    private function prepare(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
        array $dates,
    ): PreparedInvoiceDocument {
        $items = $this->itemBuilder->build($order, $series);
        $totals = $this->totalsCalculator->calculateDocument($items, $order);
        $information = $this->informationRenderer->render($series, $order);
        $attributes = $this->snapshotBuilder->build(
            $order,
            $series,
            $context,
            $dates,
            $totals,
            $items,
            $information,
        );

        return new PreparedInvoiceDocument(
            invoiceAttributes: $attributes,
            itemAttributes: $items,
            hashPayload: $this->hashPayload($attributes, $items),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function hashPayload(array $attributes, array $items): array
    {
        $hashItems = array_map(static fn (array $item): array => [
            'line_type' => $item['line_type'],
            'position' => $item['position'],
            'name' => $item['name'],
            'description' => $item['description'],
            'unit_name' => $item['unit_name'],
            'quantity' => $item['quantity'],
            'unit_price_net' => $item['unit_price_net'],
            'unit_price_gross' => $item['unit_price_gross'],
            'total_net' => $item['total_net'],
            'total_vat' => $item['total_vat'],
            'total_gross' => $item['total_gross'],
            'vat_rate' => $item['vat_rate'],
            'vat_code' => $item['vat_code'],
            'gtu_codes' => $item['gtu_codes'],
        ], $items);

        return [
            'seller_snapshot' => $attributes['seller_snapshot'],
            'buyer_snapshot' => $attributes['buyer_snapshot'],
            'recipient_snapshot' => $attributes['recipient_snapshot'],
            'items' => $hashItems,
            'currency' => $attributes['currency'],
            'payment_snapshot' => $attributes['payment_snapshot'],
            'shipping_snapshot' => $attributes['shipping_snapshot'],
            'sale_date' => $attributes['sale_date'],
            'payment_due_date' => $attributes['payment_due_date'],
            'additional_information_text' => $attributes['additional_information_text'],
            'series_settings_snapshot' => $attributes['series_settings_snapshot'],
            'total_net' => $attributes['total_net'],
            'total_vat' => $attributes['total_vat'],
            'total_gross' => $attributes['total_gross'],
            'paid_amount' => $attributes['paid_amount'],
            'amount_due' => $attributes['amount_due'],
            'tax_summary_snapshot' => $attributes['tax_summary_snapshot'],
        ];
    }
}
