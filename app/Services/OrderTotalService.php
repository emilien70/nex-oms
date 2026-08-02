<?php

namespace App\Services;

use App\Exceptions\OrderCurrencyException;
use App\Models\Order;
use App\Support\CurrencyCatalog;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class OrderTotalService
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly CurrencyCatalog $currencies,
    ) {}

    public function lineTotal(string $unitPriceGross, int $quantity): string
    {
        return $this->decimal->multiply(
            $this->decimal->normalize($unitPriceGross, 2),
            $quantity,
            2,
        );
    }

    public function recalculate(Order $order): string
    {
        $orderCurrency = $this->currencies->normalize($order->currency);
        $itemsTotal = '0.00';

        foreach ($order->items()->get(['currency', 'total_price_gross']) as $item) {
            $itemCurrency = $this->currencies->normalize($item->currency) ?? $orderCurrency;

            if ($orderCurrency === null || $itemCurrency !== $orderCurrency) {
                throw new OrderCurrencyException(
                    'Nie można obliczyć wartości zamówienia zawierającego pozycje w różnych walutach.',
                );
            }

            $itemsTotal = $this->decimal->add(
                $itemsTotal,
                $this->decimal->normalize((string) $item->total_price_gross, 2),
            );
        }

        $delivery = $this->decimal->normalize((string) ($order->delivery_cost_gross ?? '0'), 2);
        if ($orderCurrency === null && $this->decimal->compare($delivery, '0.00') !== 0) {
            throw new OrderCurrencyException(
                'Nie można obliczyć wartości zamówienia bez ustalonej waluty.',
            );
        }

        $totalGross = $this->decimal->add($itemsTotal, $delivery);

        $order->update([
            'total_gross' => $totalGross,
        ]);

        return $totalGross;
    }

    public function remainingDue(Order $order): string
    {
        return $this->decimal->max(
            $this->decimal->subtract(
                $this->decimal->normalize((string) ($order->total_gross ?? '0'), 2),
                $this->decimal->normalize((string) ($order->paid_amount ?? '0'), 2),
            ),
            '0.00',
        );
    }
}
