<?php

namespace App\Services;

use App\Exceptions\OrderCurrencyException;
use App\Models\Order;
use App\Support\CurrencyCatalog;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialLimits;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class OrderTotalService
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly CurrencyCatalog $currencies,
        private readonly InvoiceFinancialValueValidator $financial,
        private readonly OrderPaymentStateService $paymentStates,
    ) {}

    public function lineTotal(string $unitPriceGross, int $quantity): string
    {
        $unitPriceGross = $this->financial->assertOrderMoney(
            $unitPriceGross,
            'Cena brutto przekracza maksymalną obsługiwaną wartość.',
        );
        if ($quantity < 1 || $quantity > InvoiceFinancialLimits::ORDER_QUANTITY_MAX) {
            throw new InvoiceDomainException(
                'invoice_financial_value_out_of_range',
                'Ilość przekracza maksymalny obsługiwany zakres.',
            );
        }

        return $this->financial->assertOrderMoney($this->decimal->multiply(
            $unitPriceGross,
            $quantity,
            2,
        ), 'Wartość pozycji przekracza maksymalny obsługiwany zakres.');
    }

    /**
     * @param  array{payment_status: string, paid_amount: string}|null  $explicitPaymentState
     */
    public function recalculate(Order $order, ?array $explicitPaymentState = null): string
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

            $itemTotal = $this->financial->assertOrderMoney(
                (string) $item->total_price_gross,
                'Wartość pozycji przekracza maksymalny obsługiwany zakres.',
            );
            $itemsTotal = $this->decimal->add(
                $itemsTotal,
                $itemTotal,
            );
            $itemsTotal = $this->financial->assertOrderMoney(
                $itemsTotal,
                'Wartość zamówienia przekracza maksymalny obsługiwany zakres.',
            );
        }

        $delivery = $this->financial->assertOrderMoney(
            (string) ($order->delivery_cost_gross ?? '0'),
            'Koszt wysyłki przekracza maksymalną obsługiwaną wartość.',
        );
        if ($orderCurrency === null && $this->decimal->compare($delivery, '0.00') !== 0) {
            throw new OrderCurrencyException(
                'Nie można obliczyć wartości zamówienia bez ustalonej waluty.',
            );
        }

        $totalGross = $this->decimal->add($itemsTotal, $delivery);
        $totalGross = $this->financial->assertOrderMoney(
            $totalGross,
            'Wartość zamówienia przekracza maksymalny obsługiwany zakres.',
        );
        $paymentState = $explicitPaymentState === null
            ? $this->paymentStates->afterTotalRecalculation($order, $totalGross)
            : $this->paymentStates->explicit(
                $totalGross,
                $explicitPaymentState['paid_amount'],
                $explicitPaymentState['payment_status'],
            );

        $order->update([
            'total_gross' => $totalGross,
            ...$paymentState,
        ]);

        return $totalGross;
    }

    public function remainingDue(Order $order): string
    {
        $total = $this->financial->assertOrderMoney(
            (string) ($order->total_gross ?? '0'),
            'Wartość zamówienia przekracza maksymalny obsługiwany zakres.',
        );
        $paid = $this->financial->assertOrderMoney(
            (string) ($order->paid_amount ?? '0'),
            'Kwota zapłacona przekracza maksymalną obsługiwaną wartość.',
        );

        return $this->decimal->max(
            $this->decimal->subtract(
                $total,
                $paid,
            ),
            '0.00',
        );
    }
}
