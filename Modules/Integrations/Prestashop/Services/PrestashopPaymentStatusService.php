<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use App\Services\OrderPaymentStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Invoices\Services\InvoiceDecimalCalculator;

class PrestashopPaymentStatusService
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly OrderPaymentStateService $paymentStates,
    ) {}

    public function available(): bool
    {
        return Schema::hasColumns('prestashop_order_links', [
            'external_payment_paid', 'status_paid_amount', 'status_paid_at',
        ]);
    }

    public function isPaidState(PrestashopAccount $account, ?int $stateId): bool
    {
        if ($stateId === null) {
            return false;
        }

        $state = $account->orderStates()->where('external_id', $stateId)->first();

        if (! $state) {
            throw new PrestashopImportException('Nieznany status PrestaShop. Odswiez konfiguracje statusow testem polaczenia.');
        }

        return (bool) $state->paid;
    }

    public function observe(
        PrestashopOrderLink $link,
        bool $paid,
        string $remoteTotal,
        string $currency,
        ?string $importedPaidAmount = null,
    ): bool {
        if (! $this->available()) {
            return false;
        }

        return DB::transaction(function () use ($link, $paid, $remoteTotal, $currency, $importedPaidAmount): bool {
            $link = PrestashopOrderLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $order = Order::query()->lockForUpdate()->find($link->order_id);

            if (! $order || $order->source !== 'prestashop') {
                return false;
            }

            $link->external_payment_paid = $paid;

            if (! $paid || $link->status_paid_at || $this->decimal->compare($remoteTotal, '0.00') <= 0) {
                $link->save();

                return false;
            }

            if (strtoupper($currency) !== strtoupper((string) $order->currency)
                || $this->decimal->compare($remoteTotal, (string) $order->total_gross) !== 0) {
                throw new PrestashopImportException('Kwota lub waluta zamowienia PrestaShop rozni sie od OMS. Nie mozna uznac oplacenia wedlug statusu.');
            }

            $before = $this->decimal->normalize($importedPaidAmount ?? (string) $order->paid_amount, 2);
            if ($this->decimal->compare($before, $remoteTotal) > 0) {
                throw new PrestashopImportException('Saldo wplat OMS przekracza wartosc zamowienia.');
            }
            $state = $this->paymentStates->forPaidAmountUpdate($order, $remoteTotal);
            $nativeTotal = '0.00';
            foreach ($link->payments()->get() as $payment) {
                $nativeTotal = $this->decimal->add($nativeTotal, (string) $payment->amount);
            }
            if ($this->decimal->compare($nativeTotal, $remoteTotal) > 0) {
                throw new PrestashopImportException('Suma wplat przekracza wartosc zamowienia PrestaShop.');
            }
            if ($this->decimal->compare($before, $state['paid_amount']) >= 0
                && $this->decimal->compare($nativeTotal, $state['paid_amount']) >= 0) {
                $link->save();

                return false;
            }
            $accountedAt = now();
            $link->forceFill([
                'status_paid_amount' => $state['paid_amount'],
                'status_paid_at' => $accountedAt,
            ])->save();

            if ($this->decimal->compare($before, $state['paid_amount']) >= 0) {
                return false;
            }

            $order->forceFill([
                'paid_amount' => $state['paid_amount'],
                'payment_status' => $state['payment_status'],
                'paid_at' => $order->paid_at ?? $accountedAt,
            ])->save();
            $amount = $this->decimal->subtract($state['paid_amount'], $before);
            $order->events()->create([
                'event_type' => 'prestashop_status_payment_registered',
                'title' => 'Opłacenie na podstawie statusu PrestaShop',
                'description' => 'Kwota: '.$state['paid_amount'].' '.$order->currency,
                'payload' => [
                    'prestashop_order_link_id' => $link->getKey(),
                    'external_order_id' => $link->external_order_id,
                    'amount' => $amount,
                    'paid_amount_before' => $before,
                    'paid_amount_after' => $state['paid_amount'],
                    'payment_basis' => 'prestashop_paid_flag',
                ],
            ]);

            // A status declaration is not a native payment row and has its own event identity.
            OrderPaymentRegistered::dispatch(
                order: $order,
                paymentRecordId: (int) $link->getKey(),
                paymentSource: 'prestashop',
                providerPaymentId: null,
                amount: $amount,
                currency: (string) $order->currency,
                paidAmountBefore: $before,
                paidAmountAfter: $state['paid_amount'],
                paymentState: 'paid',
                accountedAt: $accountedAt->toIso8601String(),
                paymentIdentity: 'prestashop-paid-status:'.$link->getKey(),
            );

            return true;
        }, 3);
    }

    public function hasMissingTransactionId(Order $order): bool
    {
        if ($order->source !== 'prestashop' || $this->decimal->compare((string) $order->total_gross, '0.00') <= 0) {
            return false;
        }

        foreach ($order->prestashopOrderLinks as $link) {
            if (! $link->account?->warn_missing_transaction_id) {
                continue;
            }

            $paid = $link->external_payment_paid
                ?? $link->account->orderStates()->where('external_id', $link->external_order_state_id)->value('paid');

            if ($paid && ($link->payments->isEmpty() || $link->payments->contains(
                fn ($payment): bool => blank($payment->transaction_id),
            ))) {
                return true;
            }
        }

        return false;
    }
}
