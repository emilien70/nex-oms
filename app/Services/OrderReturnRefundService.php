<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnRefundAllocation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;

class OrderReturnRefundService
{
    public function identity(OrderReturn $return): ?array
    {
        if ($return->source !== 'allegro') {
            return null;
        }
        if ($return->creation_method === 'allegro_import') {
            return ['environment' => $return->environment, 'seller_user_id' => $return->seller_user_id, 'external_order_id' => $return->external_order_id];
        }
        if ($snapshot = data_get($return->order_snapshot, 'allegro_identity')) {
            return $snapshot;
        }

        return $return->order ? $this->orderIdentity($return->order) : null;
    }

    public function orderIdentity(Order $order): ?array
    {
        if ($order->source !== 'allegro' || ! $link = $order->allegroOrderLink) {
            return null;
        }

        return ['environment' => $link->environment, 'seller_user_id' => $link->seller_user_id, 'external_order_id' => $link->external_order_id];
    }

    public function query(?array $identity): Builder
    {
        return AllegroPaymentRefund::query()->when($identity, fn ($q) => $q->where($identity), fn ($q) => $q->whereRaw('1 = 0'));
    }

    public function summary(OrderReturn $return): array
    {
        $identity = $this->identity($return);
        $pending = $identity && $this->query($identity)->whereNotIn('status', ['SUCCESS', 'CANCELED', 'CANCELLED'])->exists();

        return $this->summaryState($return, (bool) $pending);
    }

    public function summaries(Collection $returns): Collection
    {
        $returns->loadMissing(['order.allegroOrderLink', 'refundAllocations.refund.allocations']);
        $identities = $returns->map(fn ($return) => $this->identity($return))->filter()->unique(fn ($id) => json_encode($id))->values();
        $pending = $identities->isEmpty() ? collect() : AllegroPaymentRefund::query()->where(function ($query) use ($identities): void {
            foreach ($identities as $identity) {
                $query->orWhere($identity);
            }
        })->whereNotIn('status', ['SUCCESS', 'CANCELED', 'CANCELLED'])->get(['environment', 'seller_user_id', 'external_order_id'])
            ->mapWithKeys(fn ($refund) => [json_encode($refund->only(['environment', 'seller_user_id', 'external_order_id'])) => true]);

        return $returns->mapWithKeys(fn ($return) => [$return->id => $this->summaryState($return, isset($pending[json_encode($this->identity($return))]))]);
    }

    private function summaryState(OrderReturn $return, bool $pending): array
    {
        $products = BigDecimal::zero()->toScale(2);
        $delivery = BigDecimal::zero()->toScale(2);
        $review = false;
        foreach ($return->refundAllocations as $allocation) {
            if (BigDecimal::of($allocation->products_value)->plus($allocation->delivery_value)->isZero()) {
                continue;
            }
            if (! $allocation->refund || ! $allocation->refund->allocationsAreValid() || $allocation->currency !== $return->currency) {
                $review = true;

                continue;
            }
            $review = $review || filled($allocation->refund->sync_error);
            $products = $products->plus($allocation->products_value);
            $delivery = $delivery->plus($allocation->delivery_value);
        }
        $total = $products->plus($delivery);

        return ['products' => (string) $products, 'delivery' => (string) $delivery, 'total' => (string) $total,
            'tone' => ($review || $pending) ? 'warning' : ($total->isPositive() ? 'success' : 'secondary'),
            'label' => $review ? 'Refundacja wymaga sprawdzenia' : ($pending ? 'Refundacje zamówienia: w toku lub wymagają sprawdzenia' : ($total->isPositive() ? 'Potwierdzona refundacja: '.$total.' '.$return->currency : 'Brak przypisanej potwierdzonej refundacji'))];
    }

    public function allocationState(AllegroPaymentRefund $refund, OrderReturn $return): array
    {
        $current = $refund->allocations->firstWhere('order_return_id', $return->id);
        $products = BigDecimal::zero();
        $delivery = BigDecimal::zero();
        foreach ($refund->allocations->where('order_return_id', '!=', $return->id) as $allocation) {
            $products = $products->plus($allocation->products_value);
            $delivery = $delivery->plus($allocation->delivery_value);
        }
        $maxProducts = BigDecimal::of($refund->products_value ?? '0.00')->minus($products);
        $maxDelivery = BigDecimal::of($refund->delivery_value)->minus($delivery);

        return ['current' => $current, 'products_max' => (string) ($maxProducts->isNegative() ? BigDecimal::zero()->toScale(2) : $maxProducts),
            'delivery_max' => (string) ($maxDelivery->isNegative() ? BigDecimal::zero()->toScale(2) : $maxDelivery),
            'managed' => $refund->command !== null,
            'can_allocate' => ! $refund->command && $refund->status === 'SUCCESS' && $refund->products_value !== null && ! $refund->sync_error && $refund->currency === $return->currency];
    }

    public function allocate(int $returnId, int $refundId, array $data): void
    {
        DB::transaction(function () use ($returnId, $refundId, $data): void {
            $identity = $this->identity(OrderReturn::query()->findOrFail($returnId));
            if ($identity) {
                AllegroOrderLink::query()->where($identity)->lockForUpdate()->first();
                if (AllegroRefundCommand::query()->where($identity)->whereIn('state', AllegroRefundCommand::RESERVING_STATES)->exists()) {
                    $this->invalid('Zwrot wpłaty jest w toku lub wymaga sprawdzenia. Przypisania są chwilowo zablokowane.');
                }
            }
            if (AllegroRefundCommand::query()->where('allegro_payment_refund_id', $refundId)->exists()) {
                $this->invalid('To przypisanie jest obsługiwane przez zlecenie zwrotu wpłaty OMS i nie może być zmieniane ręcznie.');
            }
            // The refund lock serializes allocations of the same money, including different return cards.
            $refund = AllegroPaymentRefund::query()->lockForUpdate()->findOrFail($refundId);
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($returnId);
            if ($refund->lock_version !== (int) $data['expected_refund_version'] || $return->lock_version !== (int) $data['expected_return_version']) {
                $this->invalid('Dane zmieniły się w innym oknie. Odśwież kartę.');
            }
            $identity = $this->identity($return);
            if (! $identity || $refund->currency !== $return->currency) {
                $this->invalid('Refundacja nie dotyczy tego zamówienia lub waluty.');
            }
            foreach ($identity as $field => $value) {
                if (! $value || $value !== $refund->$field) {
                    $this->invalid('Refundacja nie dotyczy tego konta i zamówienia.');
                }
            }
            $products = BigDecimal::of($data['products_value'])->toScale(2);
            $delivery = BigDecimal::of($data['delivery_value'])->toScale(2);
            if ($products->isNegative() || $delivery->isNegative()) {
                $this->invalid('Kwoty nie mogą być ujemne.');
            }
            $clearing = $products->isZero() && $delivery->isZero();
            if (! $clearing && ($refund->status !== 'SUCCESS' || $refund->products_value === null || $refund->sync_error)) {
                $this->invalid('Można przypisać wyłącznie potwierdzoną refundację ze znanym podziałem kwot i bez błędu odczytu.');
            }
            $allocations = $refund->allocations()->get();
            $current = $allocations->firstWhere('order_return_id', $return->id);
            $usedProducts = BigDecimal::zero();
            $usedDelivery = BigDecimal::zero();
            foreach ($allocations->where('order_return_id', '!=', $return->id) as $allocation) {
                $usedProducts = $usedProducts->plus($allocation->products_value);
                $usedDelivery = $usedDelivery->plus($allocation->delivery_value);
            }
            if (! $clearing && ($usedProducts->plus($products)->isGreaterThan($refund->products_value) || $usedDelivery->plus($delivery)->isGreaterThan($refund->delivery_value))) {
                $this->invalid('Kwota przekracza nieprzypisaną część refundacji produktów lub dostawy.');
            }
            $returnProducts = BigDecimal::zero();
            foreach ($return->refundAllocations()->where('allegro_payment_refund_id', '!=', $refund->id)->get() as $allocation) {
                $returnProducts = $returnProducts->plus($allocation->products_value);
            }
            if (! $clearing && $returnProducts->plus($products)->isGreaterThan($return->total_value)) {
                $this->invalid('Produkty tego zwrotu są już rozliczone lub przypisana kwota przekracza ich wartość.');
            }
            if (($current?->products_value ?? '0.00') === (string) $products && ($current?->delivery_value ?? '0.00') === (string) $delivery) {
                return;
            }
            $before = ['products' => $current?->products_value ?? '0.00', 'delivery' => $current?->delivery_value ?? '0.00'];
            OrderReturnRefundAllocation::query()->updateOrCreate(['allegro_payment_refund_id' => $refund->id, 'order_return_id' => $return->id], [
                'return_number' => $return->id, 'currency' => $refund->currency, 'products_value' => (string) $products, 'delivery_value' => (string) $delivery,
            ]);
            $return->events()->create(['type' => 'refund_allocation_changed', 'status' => 'Przypisanie refundacji', 'observed_at' => now(),
                'data' => ['refund_id' => $refund->external_refund_id, 'currency' => $refund->currency, 'before' => $before, 'products' => (string) $products, 'delivery' => (string) $delivery]]);
            $snapshot = $return->order_snapshot ?? [];
            $snapshot['allegro_identity'] = $identity;
            $return->update(['lock_version' => $return->lock_version + 1, 'order_snapshot' => $snapshot]);
            $refund->increment('lock_version');
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['refund_allocation' => $message]);
    }
}
