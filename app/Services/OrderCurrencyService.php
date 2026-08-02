<?php

namespace App\Services;

use App\Exceptions\OrderCurrencyException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\CurrencyCatalog;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class OrderCurrencyService
{
    public function __construct(
        private readonly CurrencyCatalog $currencies,
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    public function currencyForNewItem(Order $order, mixed $requestedCurrency): string
    {
        $currency = $this->currencies->require($requestedCurrency);
        $orderCurrency = $this->currencies->normalize($order->currency);

        if ($orderCurrency !== null) {
            if (! $this->currencies->exists($orderCurrency) && $this->isMoneyEmpty($order)) {
                $order->currency = $currency;
                $order->save();

                return $currency;
            }

            $this->ensureMatchesOrder($currency, $orderCurrency);

            return $currency;
        }

        if (! $this->isMoneyEmpty($order)) {
            throw new OrderCurrencyException(
                'Nie można ustawić waluty pierwszej pozycji, ponieważ zamówienie zawiera już wartości pieniężne.',
            );
        }

        $order->currency = $currency;
        $order->save();

        return $currency;
    }

    public function currencyForExistingItem(
        Order $order,
        OrderItem $item,
        mixed $requestedCurrency,
    ): string {
        $currentItemCurrency = $this->currencies->normalize($item->currency)
            ?? $this->currencies->normalize($order->currency);
        $candidate = $requestedCurrency === null || $requestedCurrency === ''
            ? $currentItemCurrency
            : $this->currencies->normalize($requestedCurrency);

        if (! $this->currencies->isAllowed($candidate, $currentItemCurrency)) {
            throw new OrderCurrencyException(CurrencyCatalog::INVALID_CURRENCY_MESSAGE);
        }

        $orderCurrency = $this->currencies->normalize($order->currency);
        if ($orderCurrency === null) {
            throw new OrderCurrencyException(
                'Nie można zmienić waluty pozycji, ponieważ zamówienie nie ma ustalonej waluty.',
            );
        }

        $this->ensureMatchesOrder((string) $candidate, $orderCurrency);

        return (string) $candidate;
    }

    public function currencyForOrder(Order $order, mixed $requestedCurrency): string
    {
        $current = $this->currencies->normalize($order->currency);
        $candidate = $this->currencies->normalize($requestedCurrency);

        if (! $this->currencies->isAllowed($candidate, $current)) {
            throw new OrderCurrencyException(CurrencyCatalog::INVALID_CURRENCY_MESSAGE);
        }

        if ($current !== null && $candidate !== $current && ! $this->isMoneyEmpty($order)) {
            throw new OrderCurrencyException(
                'Nie można zmienić waluty zamówienia zawierającego pozycje lub wartości pieniężne.',
            );
        }

        return (string) $candidate;
    }

    public function isMoneyEmpty(Order $order): bool
    {
        return ! $order->items()->exists()
            && $this->decimal->compare((string) ($order->total_gross ?? '0'), '0.00') === 0
            && $this->decimal->compare((string) ($order->paid_amount ?? '0'), '0.00') === 0
            && $this->decimal->compare((string) ($order->delivery_cost_gross ?? '0'), '0.00') === 0;
    }

    public function ensureMatchesOrder(string $itemCurrency, string $orderCurrency): void
    {
        if ($itemCurrency !== $orderCurrency) {
            throw new OrderCurrencyException(
                "Waluta produktu musi być zgodna z walutą zamówienia: {$orderCurrency}.",
            );
        }
    }
}
