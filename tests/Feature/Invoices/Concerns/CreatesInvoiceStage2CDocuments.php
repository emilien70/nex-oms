<?php

namespace Tests\Feature\Invoices\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceOperationSource;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Enums\InvoicePaymentMethodSource;
use Modules\Invoices\Enums\InvoiceSaleDateSource;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceShippingVatMode;
use Modules\Invoices\Enums\InvoiceVatRateSource;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

trait CreatesInvoiceStage2CDocuments
{
    protected function createDocumentOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'external_id' => 'SHOP-100',
            'status' => Order::STATUS_NEW,
            'customer_login' => 'kupujacy',
            'customer_email' => 'klient@example.test',
            'customer_phone' => '+48 500 000 000',
            'billing_name' => 'Jan Kowalski',
            'billing_company_name' => 'Kowalski Handel',
            'billing_tax_id' => '1234567890',
            'billing_street' => 'Fakturowa',
            'billing_building_number' => '10',
            'billing_apartment_number' => '2',
            'billing_postal_code' => '00-001',
            'billing_city' => 'Warszawa',
            'billing_country_code' => 'PL',
            'shipping_name' => 'Anna Nowak',
            'shipping_company_name' => null,
            'shipping_street' => 'Dostawcza',
            'shipping_building_number' => '20',
            'shipping_apartment_number' => '4',
            'shipping_postal_code' => '30-001',
            'shipping_city' => 'Kraków',
            'shipping_country_code' => 'PL',
            'shipping_phone' => '+48 600 000 000',
            'shipping_email' => 'odbiorca@example.test',
            'currency' => 'PLN',
            'total_gross' => '123.00',
            'paid_amount' => '50.00',
            'delivery_cost_gross' => '23.00',
            'shipping_method' => 'Kurier testowy',
            'payment_status' => 'unpaid',
            'payment_method' => 'Przelew',
            'purchased_at' => '2026-07-20 10:00:00',
            'paid_at' => '2026-07-21 11:00:00',
            'notes' => "SN-001\nSN-002",
        ], $attributes));
    }

    protected function createDocumentItem(Order $order, array $attributes = []): OrderItem
    {
        return OrderItem::query()->create(array_merge([
            'order_id' => $order->getKey(),
            'product_name' => 'Produkt testowy',
            'quantity' => 1,
            'unit_price_gross' => '100.00',
            'total_price_gross' => '100.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
        ], $attributes));
    }

    protected function createDocumentSeries(
        InvoiceDocumentType $type = InvoiceDocumentType::Invoice,
        array $attributes = [],
    ): InvoiceSeries {
        $prefix = match ($type) {
            InvoiceDocumentType::Invoice => 'FV',
            InvoiceDocumentType::Proforma => 'PF',
            InvoiceDocumentType::Correction => 'KOR',
        };

        return InvoiceSeries::query()->create(array_merge([
            'document_type' => $type,
            'name' => $prefix.' test '.uniqid(),
            'number_format' => $prefix.' %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'is_active' => true,
            'default_currency' => 'PLN',
            'seller_name' => 'NEX Seller sp. z o.o.',
            'seller_tax_id' => '9876543210',
            'seller_street' => 'Sprzedawcy',
            'seller_building_number' => '1',
            'seller_postal_code' => '40-001',
            'seller_city' => 'Katowice',
            'seller_country_code' => 'PL',
            'place_of_issue' => 'Katowice',
            'issuer_name' => 'Operator NEX-OMS',
            'additional_information_template' => "Numery:\n[uwagi_sprzedawcy]",
            'vat_rate_source' => InvoiceVatRateSource::OrderItem,
            'include_shipping' => true,
            'shipping_vat_mode' => InvoiceShippingVatMode::HighestItem,
            'skip_zero_price_items' => false,
            'payment_method_source' => InvoicePaymentMethodSource::Order,
            'sale_date_source' => InvoiceSaleDateSource::OrderDate,
            'payment_due_mode' => InvoicePaymentDueMode::None,
        ], $attributes))->refresh();
    }

    protected function documentContext(
        string $date = '2026-07-28 12:30:00',
        InvoiceOperationSource $source = InvoiceOperationSource::Manual,
    ): InvoiceOperationContext {
        return new InvoiceOperationContext(
            source: $source,
            actorSnapshot: ['type' => 'operator', 'id' => 7, 'name' => 'Tester'],
            occurredAt: CarbonImmutable::parse($date, config('app.timezone')),
        );
    }
}
