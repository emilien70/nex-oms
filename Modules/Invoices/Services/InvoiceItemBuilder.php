<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Enums\InvoiceShippingVatMode;
use Modules\Invoices\Enums\InvoiceVatRateSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\InvoiceSeries;

class InvoiceItemBuilder
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly InvoiceFinancialValueValidator $financial,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(Order $order, InvoiceSeries $series): array
    {
        $items = [];
        $position = 1;

        foreach ($order->items()->orderBy('id')->get() as $orderItem) {
            $unitGross = $this->financial->assertOrderMoney(
                (string) $orderItem->unit_price_gross,
                'Cena brutto przekracza maksymalną obsługiwaną wartość.',
            );
            $totalGross = $this->financial->assertOrderMoney(
                (string) $orderItem->total_price_gross,
                'Wartość pozycji przekracza maksymalny obsługiwany zakres.',
            );

            if ($series->skip_zero_price_items && $this->decimal->compare($totalGross, '0.00') === 0) {
                continue;
            }

            $vatRate = $this->productVatRate($orderItem, $series);
            $amounts = $this->totals->calculateLine(
                $unitGross,
                $totalGross,
                $vatRate,
            );

            $items[] = array_merge([
                'order_item_id' => $orderItem->getKey(),
                'product_id' => null,
                'source_invoice_item_id' => null,
                'line_type' => InvoiceItemType::Product->value,
                'position' => $position++,
                'name' => $orderItem->product_name,
                'description' => null,
                'unit_name' => 'szt.',
                'quantity' => $this->financial->assertInvoiceItemQuantity($orderItem->quantity),
                'vat_rate' => $vatRate,
                'vat_code' => null,
                'gtu_codes' => [],
                'product_snapshot' => [
                    'order_item_id' => $orderItem->getKey(),
                    'name' => $orderItem->product_name,
                ],
                'metadata' => ['source' => 'order_item'],
            ], $amounts);
        }

        $shippingMethod = trim((string) $order->shipping_method);

        if ($series->include_shipping && $shippingMethod !== '') {
            $shippingVatRate = $this->shippingVatRate($items, $series);
            $shippingGross = $this->financial->assertOrderMoney(
                (string) $order->delivery_cost_gross,
                'Koszt wysyłki przekracza maksymalną obsługiwaną wartość.',
            );
            $amounts = $this->totals->calculateLine(
                $this->decimal->normalize($shippingGross, 4),
                $shippingGross,
                $shippingVatRate,
            );

            $items[] = array_merge([
                'order_item_id' => null,
                'product_id' => null,
                'source_invoice_item_id' => null,
                'line_type' => InvoiceItemType::Shipping->value,
                'position' => $position,
                'name' => $shippingMethod,
                'description' => null,
                'unit_name' => 'usł.',
                'quantity' => '1.0000',
                'vat_rate' => $shippingVatRate,
                'vat_code' => null,
                'gtu_codes' => [],
                'product_snapshot' => null,
                'metadata' => ['source' => 'order_shipping'],
            ], $amounts);
        }

        return $items;
    }

    private function productVatRate(OrderItem $item, InvoiceSeries $series): string
    {
        $rate = match ($series->vat_rate_source) {
            InvoiceVatRateSource::Fixed => $series->default_vat_rate,
            InvoiceVatRateSource::OrderItem => $item->vat_rate,
        };

        if ($rate === null) {
            throw $this->taxException();
        }

        return $this->decimal->normalize((string) $rate, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function shippingVatRate(array $items, InvoiceSeries $series): string
    {
        if ($series->shipping_vat_mode === InvoiceShippingVatMode::Fixed) {
            if ($series->default_shipping_vat_rate === null) {
                throw $this->taxException();
            }

            return $this->decimal->normalize((string) $series->default_shipping_vat_rate, 2);
        }

        $highest = null;
        foreach ($items as $item) {
            if ($item['line_type'] !== InvoiceItemType::Product->value || $item['vat_rate'] === null) {
                continue;
            }

            $highest = $highest === null
                ? (string) $item['vat_rate']
                : $this->decimal->max($highest, (string) $item['vat_rate'], 2);
        }

        if ($highest === null) {
            throw $this->taxException();
        }

        return $highest;
    }

    private function taxException(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_tax_calculation_failed',
            'Nie można prawidłowo obliczyć wartości podatkowych dokumentu.',
        );
    }
}
