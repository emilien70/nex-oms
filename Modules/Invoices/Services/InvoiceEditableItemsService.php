<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Enums\InvoiceShippingVatMode;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;

class InvoiceEditableItemsService
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
        private readonly InvoiceFinancialValueValidator $financial,
    ) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function manualAttributes(array $data, ?InvoiceItem $item = null): array
    {
        $quantity = $this->financial->assertInvoiceItemQuantity($data['quantity']);
        $unitGross = $this->financial->assertInvoiceItemUnitPrice($data['unit_price_gross']);
        $totalGross = $this->decimal->multiplyAndRound($quantity, $unitGross, 2);
        $identity = $this->taxIdentity->normalize(
            $data['vat_rate'] ?? null,
            $data['vat_code'] ?? null,
        );

        if ($this->taxIdentity->key($identity) === null) {
            throw new InvoiceDomainException(
                'invoice_tax_calculation_failed',
                'Wybierz stawkę VAT albo podaj kod VAT.',
            );
        }

        return array_merge([
            'line_type' => $item?->line_type?->value ?? InvoiceItemType::Product->value,
            'position' => (int) $data['position'],
            'name' => trim((string) $data['name']),
            'description' => $this->nullableText($data['description'] ?? null),
            'unit_name' => trim((string) $data['unit_name']),
            'quantity' => $quantity,
            ...$identity,
            'gtu_codes' => $item?->gtu_codes ?? [],
            'product_snapshot' => $item?->product_snapshot,
            'metadata' => $item?->metadata ?? ['source' => 'manual_invoice_edit'],
        ], $this->totals->calculateLine(
            $unitGross,
            $totalGross,
            $identity['vat_rate'],
            $identity['vat_code'],
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function fromOrder(Invoice $invoice, Order $order): array
    {
        if (strtoupper(trim((string) $order->currency)) !== strtoupper(trim((string) $invoice->currency))) {
            throw new InvoiceDomainException(
                'invoice_order_currency_mismatch',
                'Nie można skopiować pozycji, ponieważ waluta zamówienia różni się od waluty Faktury.',
            );
        }

        $settings = $invoice->series_settings_snapshot;
        if (! is_array($settings)) {
            throw $this->settingsError();
        }

        $items = [];
        $position = 1;
        foreach ($order->items()->orderBy('id')->get() as $orderItem) {
            $unitGross = $this->financial->assertOrderMoney(
                (string) $orderItem->unit_price_gross,
                'Cena brutto przekracza maksymalną obsługiwaną wartość.',
            );
            $gross = $this->financial->assertOrderMoney(
                (string) $orderItem->total_price_gross,
                'Wartość pozycji przekracza maksymalny obsługiwany zakres.',
            );
            if (($settings['skip_zero_price_items'] ?? false) && $this->decimal->compare($gross, '0.00') === 0) {
                continue;
            }

            $vatRate = ($settings['vat_rate_source'] ?? null) === 'fixed'
                ? ($settings['default_vat_rate'] ?? null)
                : $orderItem->vat_rate;
            if ($vatRate === null) {
                throw $this->taxError();
            }
            $vatRate = $this->decimal->normalize((string) $vatRate, 2);

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
                'product_snapshot' => ['order_item_id' => $orderItem->getKey(), 'name' => $orderItem->product_name],
                'metadata' => ['source' => 'order_item'],
            ], $this->totals->calculateLine($unitGross, $gross, $vatRate));
        }

        if (($settings['include_shipping'] ?? false) && trim((string) $order->shipping_method) !== '') {
            $rate = $this->shippingRate($items, $settings);
            $gross = $this->financial->assertOrderMoney(
                (string) $order->delivery_cost_gross,
                'Koszt wysyłki przekracza maksymalną obsługiwaną wartość.',
            );
            $items[] = array_merge([
                'order_item_id' => null,
                'product_id' => null,
                'source_invoice_item_id' => null,
                'line_type' => InvoiceItemType::Shipping->value,
                'position' => $position,
                'name' => $order->shipping_method,
                'description' => null,
                'unit_name' => 'usł.',
                'quantity' => '1.0000',
                'vat_rate' => $rate,
                'vat_code' => null,
                'gtu_codes' => [],
                'product_snapshot' => null,
                'metadata' => ['source' => 'order_shipping'],
            ], $this->totals->calculateLine($this->decimal->normalize($gross, 4), $gross, $rate));
        }

        if ($items === []) {
            throw new InvoiceDomainException('invoice_items_empty', 'Faktura musi zawierać co najmniej jedną pozycję.');
        }

        return $items;
    }

    /** @param array<int, array<string, mixed>> $items
     * @param  array<string, mixed>  $settings
     */
    private function shippingRate(array $items, array $settings): string
    {
        if (($settings['shipping_vat_mode'] ?? null) === InvoiceShippingVatMode::Fixed->value) {
            if (($settings['default_shipping_vat_rate'] ?? null) === null) {
                throw $this->taxError();
            }

            return $this->decimal->normalize((string) $settings['default_shipping_vat_rate'], 2);
        }

        $highest = null;
        foreach ($items as $item) {
            if ($item['line_type'] !== InvoiceItemType::Product->value) {
                continue;
            }
            $highest = $highest === null
                ? (string) $item['vat_rate']
                : $this->decimal->max($highest, (string) $item['vat_rate'], 2);
        }

        if ($highest === null) {
            throw $this->taxError();
        }

        return $highest;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function settingsError(): InvoiceDomainException
    {
        return new InvoiceDomainException('invoice_series_snapshot_invalid', 'Zapisane ustawienia serii Faktury są niekompletne.');
    }

    private function taxError(): InvoiceDomainException
    {
        return new InvoiceDomainException('invoice_tax_calculation_failed', 'Nie można prawidłowo obliczyć wartości podatkowych dokumentu.');
    }
}
