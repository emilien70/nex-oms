<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderReturn;
use App\Services\OrderReturnRefundService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;

class AllegroRefundContextService
{
    public function __construct(private readonly AllegroClient $client, private readonly AllegroRefundNormalizer $normalizer) {}

    public function account(OrderReturn $return, bool $write = true): AllegroAccount
    {
        $identity = app(OrderReturnRefundService::class)->identity($return);
        $account = $identity ? AllegroAccount::query()->where('environment', $identity['environment'])
            ->where('allegro_user_id', $identity['seller_user_id'])->first() : null;
        if (! $account || ! $account->isOperational() || ! in_array(AllegroAccount::PAYMENTS_READ_SCOPE, $account->granted_scopes ?? [], true)) {
            $this->invalid('Brak aktywnego konta Allegro z uprawnieniem odczytu płatności dla tego zwrotu.');
        }
        if ($write && (! $account->refund_write_enabled || $account->refund_write_identity !== $account->orderImportIdentity()
            || ! in_array(AllegroAccount::PAYMENTS_WRITE_SCOPE, $account->granted_scopes ?? [], true))) {
            $this->invalid('Włącz ręczne zwroty wpłat i połącz konto z uprawnieniem allegro:api:payments:write.');
        }

        return $account;
    }

    public function fresh(OrderReturn $return, ?int $excludeCommand = null): array
    {
        $return->load(['items.orderItem', 'order.allegroOrderLink', 'localStatus', 'refundAllocations']);
        $account = $this->account($return);
        $identity = app(OrderReturnRefundService::class)->identity($return);
        if (AllegroRefundCommand::query()->where($identity)->whereIn('state', AllegroRefundCommand::RESERVING_STATES)
            ->when($excludeCommand, fn ($q) => $q->whereKeyNot($excludeCommand))->exists()) {
            $this->invalid('Inny zwrot wpłaty tego zamówienia jest w toku lub wymaga sprawdzenia.');
        }
        $link = $return->order?->allegroOrderLink;
        if (! $link || $return->order->trashed() || $link->only(array_keys($identity)) !== $identity
            || $link->replaced_by_link_id || $return->localStatus?->code === 'rejected') {
            $this->invalid('Zwrot wymaga jednoznacznego, aktywnego powiązania z zamówieniem Allegro.');
        }
        $raw = $this->client->order($account, $identity['external_order_id']);
        $currency = $return->currency;
        $paymentId = data_get($raw, 'payment.id');
        if (($raw['id'] ?? null) !== $identity['external_order_id'] || ! Str::isUuid($paymentId ?? '')
            || data_get($raw, 'payment.type') !== 'ONLINE' || ! data_get($raw, 'payment.finishedAt')
            || data_get($raw, 'payment.reconciliation') || ! empty($raw['surcharges'])
            || ! in_array($raw['status'] ?? '', ['READY_FOR_PROCESSING', 'CANCELLED'], true)) {
            $this->invalid('Brak jednoznacznej wpłaty online. Dopłaty, pobranie i rozliczenia mieszane wymagają obsługi poza tym formularzem.');
        }
        $paid = $this->money(data_get($raw, 'payment.paidAmount'), $currency);
        $delivery = $this->money(data_get($raw, 'delivery.cost'), $currency);
        $total = BigDecimal::of($delivery);
        $source = [];
        if (! is_array($raw['lineItems'] ?? null) || ! array_is_list($raw['lineItems']) || count($raw['lineItems']) > 1000) {
            $this->invalid('Niekompletne pozycje zamówienia Allegro.');
        }
        foreach ($raw['lineItems'] as $item) {
            $id = $item['id'] ?? '';
            if (! Str::isUuid($id) || isset($source[$id]) || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1
                || $item['quantity'] > 1000000 || ! empty($item['selectedAdditionalServices']) || ! empty($item['reconciliation'])
                || ! empty($item['deposit'])) {
                $this->invalid('Nieobsługiwane lub niejednoznaczne składniki pozycji Allegro.');
            }
            $unit = $this->money($item['price'] ?? null, $currency);
            $value = (string) BigDecimal::of($unit)->multipliedBy($item['quantity'])->toScale(2);
            $source[$id] = ['id' => $id, 'offer_id' => (string) data_get($item, 'offer.id'), 'unit' => $unit, 'quantity' => $item['quantity'], 'value' => $value];
            $total = $total->plus($value);
        }
        if (! $total->isPositive() || ! $total->isEqualTo($paid) || ! $total->isEqualTo($this->money(data_get($raw, 'summary.totalToPay'), $currency))) {
            $this->invalid('Kwoty wpłaty, produktów i dostawy nie są spójne. Nie można bezpiecznie ustalić dostępnej kwoty.');
        }
        $refunds = $this->allRefunds($account, $identity['external_order_id']);
        $used = array_fill_keys(array_keys($source), '0.00');
        $usedDelivery = BigDecimal::zero();
        $usedTotal = BigDecimal::zero();
        foreach ($refunds as $refund) {
            if (in_array($refund['status'], ['CANCELED', 'CANCELLED', 'VALIDATE_FAILED'], true)) {
                continue;
            }
            if ($refund['external_payment_id'] !== $paymentId || $refund['currency'] !== $currency || $refund['products_value'] === null
                || ! in_array($refund['status'], ['SUCCESS', 'NEW', 'VALIDATED', 'IN_PROGRESS', 'WAITING'], true)
                || BigDecimal::of($refund['other_value'])->isPositive()) {
                $this->invalid('Historia refundacji zawiera niejednoznaczne lub częściowe rozliczenie. Sprawdź je w Allegro.');
            }
            foreach ($refund['components']['items'] as $item) {
                if ($item['type'] !== 'AMOUNT' || ! isset($source[$item['id']])) {
                    $this->invalid('Nie można ustalić kwot pozycji wcześniejszej refundacji. Sprawdź rozliczenie w Allegro.');
                }
                $used[$item['id']] = (string) BigDecimal::of($used[$item['id']])->plus($item['value']);
            }
            $usedDelivery = $usedDelivery->plus($refund['delivery_value']);
            $usedTotal = $usedTotal->plus($refund['total_value']);
        }
        foreach ($source as $id => $row) {
            if (BigDecimal::of($used[$id])->isGreaterThan($row['value'])) {
                $this->invalid('Historia refundacji przekracza wartość pozycji zakupu.');
            }
        }
        // A just-confirmed local command must not disappear from an eventually consistent API list.
        $knownIds = array_column($refunds, 'external_refund_id');
        $confirmed = AllegroRefundCommand::query()->where($identity)->where('state', 'success')->get();
        foreach ($confirmed as $command) {
            $id = AllegroPaymentRefund::query()->whereKey($command->allegro_payment_refund_id)->value('external_refund_id');
            if (! in_array($id, $knownIds, true)) {
                $this->invalid('Allegro nie udostępniło jeszcze pełnej historii ostatniego zwrotu. Spróbuj później.');
            }
        }
        $rows = [];
        $seen = [];
        foreach ($return->items as $item) {
            $orderItem = $item->orderItem;
            $id = $orderItem?->external_id;
            if (! $orderItem || $orderItem->order_id !== $return->order_id || ! isset($source[$id]) || isset($seen[$id])
                || $item->quantity < 1 || $item->quantity > $source[$id]['quantity'] || $item->currency !== $currency
                || (filled($item->offer_id) && (string) $item->offer_id !== $source[$id]['offer_id'])) {
                $this->invalid('Pozycja zwrotu nie ma jednoznacznego powiązania z pozycją zakupu Allegro.');
            }
            $seen[$id] = true;
            $ownUsed = BigDecimal::zero();
            foreach ($confirmed->where('return_number', $return->id) as $command) {
                foreach ($command->payload['lineItems'] ?? [] as $line) {
                    if ($line['id'] === $id) {
                        $ownUsed = $ownUsed->plus($line['value']['amount']);
                    }
                }
            }
            $sourceRemaining = BigDecimal::of($source[$id]['value'])->minus($used[$id]);
            $returnRemaining = BigDecimal::of($item->total_value)->minus($ownUsed);
            $max = BigDecimal::min($sourceRemaining, $returnRemaining, BigDecimal::of($source[$id]['unit'])->multipliedBy($item->quantity));
            if ($sourceRemaining->isNegative() || $returnRemaining->isNegative()) {
                $this->invalid('Historia refundacji przekracza wartość pozycji. Wymagane sprawdzenie.');
            }
            $rows[$item->id] = ['id' => $id, 'name' => $item->name, 'quantity' => $item->quantity, 'unit' => $source[$id]['unit'], 'max' => (string) $max->toScale(2)];
        }
        if ($rows === [] || $usedDelivery->isGreaterThan($delivery) || $usedTotal->isGreaterThan($paid)) {
            $this->invalid('Brak spójnej dostępnej kwoty refundacji.');
        }
        $allocated = BigDecimal::zero();
        foreach ($return->refundAllocations as $allocation) {
            $allocated = $allocated->plus($allocation->products_value);
        }
        $returnMax = BigDecimal::of($return->total_value)->minus($allocated);
        if ($returnMax->isNegative()) {
            $this->invalid('Zwrot posiada niespójne wcześniejsze przypisania refundacji.');
        }
        ksort($source);
        usort($refunds, fn ($a, $b) => strcmp($a['external_refund_id'], $b['external_refund_id']));
        $ledgerHash = $this->ledgerHash($identity, $excludeCommand);
        $hash = self::hash([$identity, $paymentId, $currency, $paid, $delivery, $source,
            array_column($refunds, 'source_hash'), $rows, (string) $returnMax, $return->lock_version, $account->refund_write_revision, $ledgerHash]);

        return ['account' => $account, 'identity' => $identity, 'link_id' => $link->id, 'payment_id' => $paymentId, 'currency' => $currency,
            'rows' => $rows, 'delivery_max' => (string) BigDecimal::of($delivery)->minus($usedDelivery)->toScale(2),
            'total_max' => (string) BigDecimal::of($paid)->minus($usedTotal)->toScale(2), 'return_max' => (string) $returnMax->toScale(2), 'hash' => $hash, 'ledger_hash' => $ledgerHash];
    }

    public function ledgerHash(array $identity, ?int $excludeCommand): string
    {
        return self::hash(AllegroRefundCommand::query()->where($identity)->where('state', '!=', 'draft')
            ->when($excludeCommand, fn ($query) => $query->whereKeyNot($excludeCommand))->orderBy('id')
            ->get(['id', 'state', 'external_refund_id', 'allegro_payment_refund_id'])->toArray());
    }

    public function allRefunds(AllegroAccount $account, string $orderId): array
    {
        $rows = [];
        $expectedTotal = null;
        for ($offset = 0; $offset < 10000; $offset += $count) {
            $page = $this->client->refunds($account, ['order.id' => $orderId, 'limit' => 100, 'offset' => $offset]);
            $count = $page['count'] ?? null;
            $total = $page['totalCount'] ?? null;
            if (! is_array($page['refunds'] ?? null) || ! array_is_list($page['refunds']) || ! is_int($count) || $count !== count($page['refunds'])
                || $count > 100 || ! is_int($total) || $total < $offset + $count || ($expectedTotal !== null && $total !== $expectedTotal)) {
                $this->invalid('Niekompletna historia refundacji Allegro. Spróbuj ponownie.');
            }
            $expectedTotal = $total;
            foreach ($page['refunds'] as $raw) {
                $refund = $this->normalizer->normalize($raw);
                if ($refund['external_order_id'] !== $orderId || isset($rows[$refund['external_refund_id']])) {
                    $this->invalid('Niespójna historia refundacji Allegro.');
                }
                $rows[$refund['external_refund_id']] = $refund;
            }
            if ($offset + $count === $total) {
                return array_values($rows);
            }
            if ($count === 0) {
                break;
            }
        }
        $this->invalid('Historia refundacji jest zbyt duża lub niekompletna. Wymagane sprawdzenie.');
    }

    public function money(mixed $money, string $currency): string
    {
        if (! is_array($money) || ($money['currency'] ?? null) !== $currency || ! is_string($money['amount'] ?? null)
            || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $money['amount'])) {
            $this->invalid('Nieprawidłowa kwota lub waluta.');
        }

        return (string) BigDecimal::of($money['amount'])->toScale(2);
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }

        return $data;
    }

    public function invalid(string $message): never
    {
        throw ValidationException::withMessages(['refund_payment' => $message]);
    }
}
