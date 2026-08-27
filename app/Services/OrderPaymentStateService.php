<?php

namespace App\Services;

use App\Exceptions\OrderPaymentStateException;
use App\Models\Order;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class OrderPaymentStateService
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_UNPAID,
        self::STATUS_PAID,
    ];

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceFinancialValueValidator $financial,
    ) {}

    /** @return array{payment_status: string, paid_amount: string} */
    public function explicit(string $totalGross, string $paidAmount, string $paymentStatus): array
    {
        $total = $this->total($totalGross);
        $paid = $this->paid($paidAmount);

        $this->assertSupportedStatus($paymentStatus);
        $this->assertNotOverpaid($paid, $total);

        $totalComparison = $this->decimal->compare($total, '0.00');
        $paidComparison = $this->decimal->compare($paid, '0.00');
        $isFullyPaid = $this->decimal->compare($paid, $total) === 0;

        if ($totalComparison === 0) {
            if ($paidComparison !== 0) {
                throw new OrderPaymentStateException;
            }

            return $this->state($paymentStatus, $paid);
        }

        if (($paymentStatus === self::STATUS_PAID) !== $isFullyPaid) {
            throw new OrderPaymentStateException;
        }

        return $this->state($paymentStatus, $paid);
    }

    /** @return array{payment_status: string, paid_amount: string} */
    public function forQuickUpdate(Order $order, string $finalTotalGross, string $paymentStatus): array
    {
        $total = $this->total($finalTotalGross);
        $currentTotal = $this->total((string) ($order->total_gross ?? '0'));
        $currentPaid = $this->paid((string) ($order->paid_amount ?? '0'));

        $this->assertSupportedStatus($paymentStatus);

        if ($paymentStatus === self::STATUS_PAID) {
            return $this->explicit($total, $total, self::STATUS_PAID);
        }

        $wasFullyPaid = $order->payment_status === self::STATUS_PAID
            && $this->decimal->compare($currentPaid, $currentTotal) === 0;

        if ($wasFullyPaid) {
            return $this->explicit($total, '0.00', self::STATUS_UNPAID);
        }

        return $this->explicit($total, $currentPaid, self::STATUS_UNPAID);
    }

    /** @return array{payment_status: string, paid_amount: string} */
    public function forPaidAmountUpdate(Order $order, string $paidAmount): array
    {
        $total = $this->total((string) ($order->total_gross ?? '0'));
        $paid = $this->paid($paidAmount);

        $this->assertNotOverpaid($paid, $total);

        if ($this->decimal->compare($total, '0.00') === 0) {
            $status = in_array($order->payment_status, self::STATUSES, true)
                ? $order->payment_status
                : self::STATUS_UNPAID;

            return $this->explicit($total, $paid, $status);
        }

        $status = $this->decimal->compare($paid, $total) === 0
            ? self::STATUS_PAID
            : self::STATUS_UNPAID;

        return $this->explicit($total, $paid, $status);
    }

    /** @return array{payment_status: string, paid_amount: string} */
    public function afterTotalRecalculation(Order $order, string $newTotalGross): array
    {
        $newTotal = $this->total($newTotalGross);
        $currentPaid = $this->paid((string) ($order->paid_amount ?? '0'));

        $this->assertSupportedStatus((string) $order->payment_status);
        $this->assertNotOverpaid($currentPaid, $newTotal);

        if ($this->decimal->compare($newTotal, '0.00') === 0) {
            return $this->explicit($newTotal, $currentPaid, (string) $order->payment_status);
        }

        $status = $this->decimal->compare($currentPaid, $newTotal) === 0
            ? self::STATUS_PAID
            : self::STATUS_UNPAID;

        return $this->explicit($newTotal, $currentPaid, $status);
    }

    private function total(string $value): string
    {
        return $this->financial->assertOrderMoney(
            $value,
            'Wartość zamówienia przekracza maksymalny obsługiwany zakres.',
        );
    }

    private function paid(string $value): string
    {
        return $this->financial->assertOrderMoney(
            $value,
            'Kwota zapłacona przekracza maksymalną obsługiwaną wartość.',
        );
    }

    private function assertSupportedStatus(string $paymentStatus): void
    {
        if (! in_array($paymentStatus, self::STATUSES, true)) {
            throw new OrderPaymentStateException;
        }
    }

    private function assertNotOverpaid(string $paidAmount, string $totalGross): void
    {
        if ($this->decimal->compare($paidAmount, $totalGross) > 0) {
            throw new OrderPaymentStateException(
                'Kwota zapłacona nie może przekraczać wartości zamówienia.',
            );
        }
    }

    /** @return array{payment_status: string, paid_amount: string} */
    private function state(string $paymentStatus, string $paidAmount): array
    {
        return [
            'payment_status' => $paymentStatus,
            'paid_amount' => $paidAmount,
        ];
    }
}
