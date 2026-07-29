<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use BackedEnum;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

class InvoiceSnapshotBuilder
{
    /**
     * @param  array{issue_date: string, sale_date: string, payment_due_date: ?string, issued_at: mixed}  $dates
     * @param  array<string, mixed>  $totals
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function build(
        Order $order,
        InvoiceSeries $series,
        InvoiceOperationContext $context,
        array $dates,
        array $totals,
        array $items,
        string $additionalInformation,
    ): array {
        $seller = $this->sellerSnapshot($series);
        $buyer = $this->buyerSnapshot($order);
        $recipient = $this->recipientSnapshot($order);
        $effectivePaymentMethod = match ($this->enumValue($series->payment_method_source)) {
            'fixed' => $series->fixed_payment_method,
            'none' => null,
            default => $order->payment_method,
        };
        $currency = strtoupper(trim((string) $order->currency));
        $currency = $currency !== '' ? $currency : 'PLN';
        $shippingItem = collect($items)->firstWhere('line_type', 'shipping');

        return [
            'issue_date' => $dates['issue_date'],
            'sale_date' => $dates['sale_date'],
            'payment_due_date' => $dates['payment_due_date'],
            'issued_at' => $dates['issued_at'],
            'order_reference_snapshot' => (string) $order->getKey(),
            'seller_name_snapshot' => $series->seller_name,
            'seller_tax_id_snapshot' => $series->seller_tax_id,
            'buyer_name_snapshot' => $order->billing_company_name ?: $order->billing_name,
            'buyer_tax_id_snapshot' => $order->billing_tax_id,
            'recipient_name_snapshot' => $order->shipping_name,
            'seller_snapshot' => $seller,
            'buyer_snapshot' => $buyer,
            'recipient_snapshot' => $recipient,
            'issuer_snapshot' => [
                'place_of_issue' => $series->place_of_issue,
                'issuer_name' => $series->issuer_name,
            ],
            'order_snapshot' => [
                'id' => $order->getKey(),
                'external_id' => $order->external_id,
                'source' => $order->source,
                'status' => $order->status,
                'purchased_at' => $order->purchased_at?->toIso8601String(),
                'currency' => $currency,
                'total_gross' => (string) $order->total_gross,
                'delivery_cost_gross' => (string) $order->delivery_cost_gross,
                'payment_status' => $order->payment_status,
            ],
            'payment_snapshot' => [
                'order_payment_method' => $order->payment_method,
                'effective_payment_method' => $effectivePaymentMethod,
                'payment_identifier' => $order->external_id,
                'payment_status' => $order->payment_status,
                'paid_at' => $order->paid_at?->toIso8601String(),
                'cash_on_delivery' => (bool) $order->cash_on_delivery,
                'payment_due_mode' => $this->enumValue($series->payment_due_mode),
                'payment_due_days' => $series->payment_due_days,
                'payment_due_date' => $dates['payment_due_date'],
                'paid_amount' => $totals['paid_amount'],
                'amount_due' => $totals['amount_due'],
            ],
            'shipping_snapshot' => [
                'included' => $shippingItem !== null,
                'method' => $order->shipping_method,
                'gross' => (string) $order->delivery_cost_gross,
                'vat_rate' => $shippingItem['vat_rate'] ?? null,
                'pickup_point' => [
                    'name' => $order->pickup_point_name,
                    'id' => $order->pickup_point_id,
                    'address' => $order->pickup_point_address,
                    'postal_code' => $order->pickup_point_postal_code,
                    'city' => $order->pickup_point_city,
                ],
            ],
            'series_settings_snapshot' => $this->seriesSettingsSnapshot($series, $seller, $effectivePaymentMethod),
            'tax_summary_snapshot' => $totals['tax_summary_snapshot'],
            'tax_metadata_snapshot' => [],
            'additional_information_text' => $additionalInformation,
            'currency' => $currency,
            'total_net' => $totals['total_net'],
            'total_vat' => $totals['total_vat'],
            'total_gross' => $totals['total_gross'],
            'paid_amount' => $totals['paid_amount'],
            'amount_due' => $totals['amount_due'],
        ];
    }

    /** @return array<string, mixed> */
    private function sellerSnapshot(InvoiceSeries $series): array
    {
        return [
            'name' => $series->seller_name,
            'tax_id' => $series->seller_tax_id,
            'regon' => $series->seller_regon,
            'bdo' => $series->seller_bdo,
            'street' => $series->seller_street,
            'building_number' => $series->seller_building_number,
            'apartment_number' => $series->seller_apartment_number,
            'postal_code' => $series->seller_postal_code,
            'city' => $series->seller_city,
            'province' => $series->seller_province,
            'country_code' => $series->seller_country_code,
            'email' => $series->seller_email,
            'phone' => $series->seller_phone,
            'bank_name' => $series->seller_bank_name,
            'bank_account' => $series->seller_bank_account,
            'bank_swift' => $series->seller_bank_swift,
        ];
    }

    /** @return array<string, mixed> */
    private function buyerSnapshot(Order $order): array
    {
        return [
            'name' => $order->billing_name,
            'company_name' => $order->billing_company_name,
            'tax_id' => $order->billing_tax_id,
            'street' => $order->billing_street,
            'building_number' => $order->billing_building_number,
            'apartment_number' => $order->billing_apartment_number,
            'postal_code' => $order->billing_postal_code,
            'city' => $order->billing_city,
            'province' => $order->billing_province,
            'country_code' => $order->billing_country_code,
            'email' => $order->billing_email,
            'phone' => $order->billing_phone,
        ];
    }

    /** @return array<string, mixed> */
    private function recipientSnapshot(Order $order): array
    {
        return [
            'name' => $order->shipping_name,
            'company_name' => $order->shipping_company_name,
            'street' => $order->shipping_street,
            'building_number' => $order->shipping_building_number,
            'apartment_number' => $order->shipping_apartment_number,
            'postal_code' => $order->shipping_postal_code,
            'city' => $order->shipping_city,
            'province' => $order->shipping_province,
            'country_code' => $order->shipping_country_code,
            'email' => $order->shipping_email,
            'phone' => $order->shipping_phone,
        ];
    }

    /**
     * @param  array<string, mixed>  $seller
     * @return array<string, mixed>
     */
    private function seriesSettingsSnapshot(
        InvoiceSeries $series,
        array $seller,
        ?string $effectivePaymentMethod,
    ): array {
        return [
            'series_id' => $series->getKey(),
            'series_name' => $series->name,
            'document_type' => $this->enumValue($series->document_type),
            'number_format' => $series->number_format,
            'reset_period' => $this->enumValue($series->reset_period),
            'fiscal_year_start_month' => $series->fiscal_year_start_month,
            'vat_rate_source' => $this->enumValue($series->vat_rate_source),
            'default_vat_rate' => $series->default_vat_rate !== null ? (string) $series->default_vat_rate : null,
            'include_shipping' => (bool) $series->include_shipping,
            'shipping_vat_mode' => $this->enumValue($series->shipping_vat_mode),
            'default_shipping_vat_rate' => $series->default_shipping_vat_rate !== null
                ? (string) $series->default_shipping_vat_rate
                : null,
            'skip_zero_price_items' => (bool) $series->skip_zero_price_items,
            'payment_method_source' => $this->enumValue($series->payment_method_source),
            'effective_payment_method' => $effectivePaymentMethod,
            'sale_date_source' => $this->enumValue($series->sale_date_source),
            'payment_due_mode' => $this->enumValue($series->payment_due_mode),
            'payment_due_days' => $series->payment_due_days,
            'seller' => $seller,
            'additional_information_template' => $series->additional_information_template,
            'document_title' => $series->document_title,
            'print_header' => $series->print_header,
            'unit_price_mode' => $this->enumValue($series->unit_price_mode),
            'show_vat_column' => (bool) $series->show_vat_column,
            'show_order_number' => (bool) $series->show_order_number,
            'show_buyer_signature' => (bool) $series->show_buyer_signature,
            'show_original_copy' => (bool) $series->show_original_copy,
            'print_template' => $this->enumValue($series->print_template),
            'primary_language' => $this->enumValue($series->primary_language),
            'secondary_language' => $this->enumValue($series->secondary_language),
            'copies_count' => $series->copies_count,
            'logo_path' => $series->logo_path,
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
