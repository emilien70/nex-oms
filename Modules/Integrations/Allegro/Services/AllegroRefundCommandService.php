<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderReturn;
use App\Models\OrderReturnRefundAllocation;
use App\Services\OrderReturnRefundService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\ProcessAllegroRefundCommandJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;
use Throwable;

class AllegroRefundCommandService
{
    public function __construct(private readonly AllegroRefundContextService $context, private readonly AllegroClient $client,
        private readonly AllegroRefundNormalizer $normalizer, private readonly AllegroRefundImportService $importer) {}

    public function prepare(OrderReturn $return, array $data, string $session): AllegroRefundCommand
    {
        $fresh = $this->context->fresh($return);
        if (($data['context_hash'] ?? '') !== $fresh['hash']) {
            $this->context->invalid('Dane zmieniły się. Otwórz ponownie formularz zwrotu wpłaty.');
        }
        $items = [];
        $selection = [];
        $products = BigDecimal::zero()->toScale(2);
        foreach ($data['items'] as $key => $chosen) {
            if (! isset($fresh['rows'][$key])) {
                $this->context->invalid('Nieznana pozycja zwrotu.');
            }
            if (empty($chosen['selected'])) {
                continue;
            }
            $row = $fresh['rows'][$key];
            $quantity = filter_var($chosen['quantity'] ?? null, FILTER_VALIDATE_INT);
            $amount = $this->context->money(['amount' => $chosen['amount'] ?? null, 'currency' => $fresh['currency']], $fresh['currency']);
            if (! $quantity || $quantity < 1 || $quantity > $row['quantity'] || ! BigDecimal::of($amount)->isPositive()
                || BigDecimal::of($amount)->isGreaterThan($row['max']) || BigDecimal::of($amount)->isGreaterThan(BigDecimal::of($row['unit'])->multipliedBy($quantity))) {
                $this->context->invalid('Kwota lub ilość przekracza dostępny limit pozycji.');
            }
            $items[] = ['id' => $row['id'], 'type' => 'AMOUNT', 'value' => ['amount' => $amount, 'currency' => $fresh['currency']]];
            $selection[] = ['name' => $row['name'], 'quantity' => $quantity, 'amount' => $amount];
            $products = $products->plus($amount);
        }
        usort($items, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $delivery = empty($data['include_delivery']) ? '0.00' : $this->context->money(['amount' => $data['delivery_amount'] ?? null, 'currency' => $fresh['currency']], $fresh['currency']);
        $total = $products->plus($delivery);
        if (! $total->isPositive() || $total->isGreaterThan($fresh['total_max']) || $products->isGreaterThan($fresh['return_max'])
            || BigDecimal::of($delivery)->isGreaterThan($fresh['delivery_max']) || ! isset(AllegroRefundCommand::REASONS[$data['reason'] ?? ''])) {
            $this->context->invalid('Kwota przekracza dostępne środki albo wybrano nieprawidłowy powód.');
        }
        $uuid = (string) Str::uuid();
        $payload = ['payment' => ['id' => $fresh['payment_id']], 'order' => ['id' => $fresh['identity']['external_order_id']],
            'commandId' => $uuid, 'reason' => $data['reason']];
        if ($items !== []) {
            $payload['lineItems'] = $items;
        }
        if (BigDecimal::of($delivery)->isPositive()) {
            $payload['delivery'] = ['value' => ['amount' => $delivery, 'currency' => $fresh['currency']]];
        }
        $comment = trim((string) ($data['seller_comment'] ?? ''));
        if (mb_strlen($comment) > 250) {
            $this->context->invalid('Komentarz może mieć najwyżej 250 znaków.');
        }
        if ($comment !== '') {
            $payload['sellerComment'] = $comment;
        }

        return AllegroRefundCommand::query()->create($fresh['identity'] + [
            'allegro_account_id' => $fresh['account']->id, 'order_id' => $return->order_id, 'order_return_id' => $return->id, 'return_number' => $return->id,
            'external_payment_id' => $fresh['payment_id'], 'command_id' => $uuid, 'session_hash' => hash('sha256', $session),
            'context_hash' => $fresh['hash'], 'payload_hash' => AllegroRefundContextService::hash($payload), 'payload' => $payload, 'selection' => $selection,
            'configuration_revision' => $fresh['account']->refund_write_revision, 'return_version' => $return->lock_version,
            'products_value' => (string) $products, 'delivery_value' => $delivery, 'total_value' => (string) $total,
            'currency' => $fresh['currency'], 'expires_at' => now()->addMinutes(5), 'state' => 'draft',
        ]);
    }

    public function confirm(AllegroRefundCommand $command, string $session): void
    {
        if (! hash_equals($command->session_hash, hash('sha256', $session))) {
            $this->context->invalid('Potwierdzenie należy do innej sesji. Otwórz formularz ponownie.');
        }
        if ($command->state !== 'draft') {
            return;
        }
        $return = OrderReturn::query()->findOrFail($command->order_return_id);
        $fresh = $this->context->fresh($return, $command->id);
        DB::transaction(function () use ($command, $fresh): void {
            $account = AllegroAccount::query()->lockForUpdate()->findOrFail($command->allegro_account_id);
            AllegroOrderLink::query()->whereKey($fresh['link_id'])->lockForUpdate()->firstOrFail();
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($command->order_return_id);
            $record = AllegroRefundCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($record->state !== 'draft') {
                return;
            }
            $this->validateContext($record, $account, $return, $fresh);
            $this->assertNoReservation($record);
            $record->update(['state' => 'queued', 'next_check_at' => now()]);
            $this->event($record, 'queued');
        }, 3);
        ProcessAllegroRefundCommandJob::dispatch($command->id)->afterCommit();
    }

    public function process(int $id): void
    {
        $command = AllegroRefundCommand::query()->find($id);
        if (! $command) {
            return;
        }
        if ($command->state !== 'queued') {
            if ($command->external_refund_id) {
                $this->refresh($command);
            }

            return;
        }
        // Re-read source facts immediately before claiming a single external write.
        try {
            $return = OrderReturn::query()->findOrFail($command->order_return_id);
            $fresh = $this->context->fresh($return, $command->id);
            $claimed = DB::transaction(function () use ($command, $fresh): bool {
                $account = AllegroAccount::query()->lockForUpdate()->findOrFail($command->allegro_account_id);
                AllegroOrderLink::query()->whereKey($fresh['link_id'])->lockForUpdate()->firstOrFail();
                $return = OrderReturn::query()->lockForUpdate()->findOrFail($command->order_return_id);
                $record = AllegroRefundCommand::query()->lockForUpdate()->findOrFail($command->id);
                if ($record->state !== 'queued') {
                    return false;
                }
                $this->validateContext($record, $account, $return, $fresh);
                $this->assertNoReservation($record);
                $record->update(['state' => 'sending', 'attempts' => 1, 'started_at' => now(), 'next_check_at' => now()->addMinutes(5)]);
                $this->event($record, 'sending');

                return true;
            }, 3);
        } catch (Throwable) {
            $this->transition($command->id, ['queued'], 'cancelled', 'Nie wysłano: konto, kwoty lub dane zwrotu zmieniły się albo nie udało się ich potwierdzić.');

            return;
        }
        if (! $claimed) {
            return;
        }
        $responded = false;
        try {
            $account = AllegroAccount::query()->findOrFail($command->allegro_account_id);
            if ($account->refund_write_revision !== $command->configuration_revision) {
                throw new AllegroApiException('Konfiguracja zmieniła się przed wysłaniem.', 403);
            }
            $raw = $this->client->initiateRefund($account, $command->fresh());
            $responded = true;
            if (Str::isUuid($raw['id'] ?? '')) {
                AllegroRefundCommand::query()->whereKey($id)->whereNull('external_refund_id')->update(['external_refund_id' => strtolower($raw['id'])]);
            }
            $this->accept($command->fresh(), $account, $raw);
        } catch (Throwable $e) {
            $definitive = ! $responded && $e instanceof AllegroApiException && in_array($e->statusCode, [400, 401, 403, 404, 422], true);
            $this->transition($id, ['sending'], $definitive ? 'failed' : 'uncertain', $definitive
                ? 'Allegro odrzuciło zlecenie. Środki nie zostały potwierdzone jako zwrócone.'
                : 'Nie potwierdzono wyniku. Nie zlecaj kolejnego zwrotu; sprawdź refundację w Allegro.');
        }
    }

    public function refresh(AllegroRefundCommand $command): void
    {
        if (! $command->external_refund_id || ! in_array($command->state, [...AllegroRefundCommand::RESERVING_STATES, 'success', 'failed'], true)) {
            return;
        }
        try {
            $account = AllegroAccount::query()->findOrFail($command->allegro_account_id);
            if ($account->environment !== $command->environment || $account->allegro_user_id !== $command->seller_user_id) {
                throw new AllegroApiException('Tożsamość konta zmieniła się.');
            }
            $page = $this->client->refunds($account, ['id' => $command->external_refund_id, 'limit' => 100, 'offset' => 0]);
            if (($page['count'] ?? null) !== 1 || ($page['totalCount'] ?? null) !== 1 || ! is_array($page['refunds'] ?? null) || count($page['refunds']) !== 1
                || ($page['refunds'][0]['id'] ?? null) !== $command->external_refund_id) {
                throw new AllegroApiException('Niekompletny wynik refundacji.');
            }
            $this->accept($command, $account, $page['refunds'][0]);
        } catch (Throwable) {
            AllegroRefundCommand::query()->whereKey($command->id)->update(['error' => 'Nie udało się sprawdzić wyniku. Zachowano poprzednie dane.', 'next_check_at' => now()->addMinutes(5)]);
        }
    }

    public function bindUncertain(AllegroRefundCommand $command, string $refundId): void
    {
        if ($command->state !== 'uncertain' || $command->external_refund_id || ! Str::isUuid($refundId)) {
            $this->context->invalid('Tego zlecenia nie można ręcznie uzgodnić.');
        }
        $account = AllegroAccount::query()->findOrFail($command->allegro_account_id);
        if ($account->environment !== $command->environment || $account->allegro_user_id !== $command->seller_user_id) {
            $this->context->invalid('Konto Allegro zmieniło się.');
        }
        $page = $this->client->refunds($account, ['id' => $refundId, 'limit' => 100, 'offset' => 0]);
        if (($page['count'] ?? null) !== 1 || ($page['totalCount'] ?? null) !== 1 || ($page['refunds'][0]['id'] ?? null) !== $refundId) {
            $this->context->invalid('Nie znaleziono jednoznacznej refundacji.');
        }
        $normalized = $this->normalizer->normalize($page['refunds'][0]);
        if (! $this->matches($command, $normalized) || $normalized['requested_at']->lt($command->started_at->subMinute())) {
            $this->context->invalid('Refundacja nie odpowiada zapisanej treści zlecenia.');
        }
        DB::transaction(function () use ($command, $refundId): void {
            $account = AllegroAccount::query()->lockForUpdate()->findOrFail($command->allegro_account_id);
            AllegroOrderLink::query()->where($command->identity())->lockForUpdate()->first();
            $record = AllegroRefundCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($record->state !== 'uncertain' || $record->external_refund_id || $account->environment !== $record->environment
                || $account->allegro_user_id !== $record->seller_user_id
                || AllegroRefundCommand::query()->where('environment', $record->environment)->where('seller_user_id', $record->seller_user_id)
                    ->where('external_refund_id', $refundId)->whereKeyNot($record->id)->exists()) {
                $this->context->invalid('Zlecenie zmieniło się. Odśwież stronę.');
            }
            $record->update(['external_refund_id' => $refundId]);
            $this->event($record, 'manual_refund_identity_confirmed');
        }, 3);
        $this->accept($command->fresh(), $account, $page['refunds'][0]);
    }

    public function dispatchDue(): void
    {
        AllegroRefundCommand::query()->where('state', 'sending')->where('started_at', '<', now()->subMinutes(5))->orderBy('id')->limit(100)->get()->each(
            fn ($command) => $this->transition($command->id, ['sending'], 'uncertain', 'Przerwano obsługę zlecenia. Wynik wymaga sprawdzenia w Allegro.')
        );
        AllegroRefundCommand::query()->where(function ($query): void {
            $query->where('state', 'queued')->orWhere(function ($query): void {
                $query->whereNotNull('external_refund_id')->whereIn('state', ['submitted', 'uncertain', 'review', 'success', 'failed']);
            });
        })->where('next_check_at', '<=', now())->orderBy('next_check_at')->limit(100)->get()->each(
            fn ($command) => ProcessAllegroRefundCommandJob::dispatch($command->id)
        );
    }

    private function accept(AllegroRefundCommand $command, AllegroAccount $account, array $raw): void
    {
        $data = $this->normalizer->normalize($raw);
        DB::transaction(function () use ($command, $account, $data): void {
            $currentAccount = AllegroAccount::query()->lockForUpdate()->find($account->id);
            if (! $currentAccount || $currentAccount->environment !== $command->environment || $currentAccount->allegro_user_id !== $command->seller_user_id) {
                throw new AllegroApiException('Tożsamość konta zmieniła się.');
            }
            AllegroOrderLink::query()->where($command->identity())->lockForUpdate()->first();
            $record = AllegroRefundCommand::query()->lockForUpdate()->findOrFail($command->id);
            $sameIdentity = $record->external_refund_id === $data['external_refund_id'] && $data['external_order_id'] === $record->external_order_id
                && $data['external_payment_id'] === $record->external_payment_id && $data['currency'] === $record->currency;
            $refund = $sameIdentity ? $this->importer->store($currentAccount, $data) : null;
            if ($record->external_refund_id !== $data['external_refund_id'] || ! $this->matches($record, $data)) {
                $record->update(['state' => 'review', 'allegro_payment_refund_id' => $refund?->id ?? $record->allegro_payment_refund_id,
                    'error' => 'Odpowiedź Allegro nie odpowiada treści zlecenia. Wymagane sprawdzenie.', 'next_check_at' => now()->addMinutes(5)]);
                $this->event($record, 'review');

                return;
            }
            $state = match ($data['status']) {
                'SUCCESS' => 'success', 'CANCELED', 'CANCELLED', 'VALIDATE_FAILED' => 'failed',
                'NEW', 'VALIDATED', 'IN_PROGRESS', 'WAITING' => 'submitted', default => 'review',
            };
            if ($state === 'success') {
                $return = OrderReturn::query()->lockForUpdate()->find($record->order_return_id);
                $current = $refund->allocations()->where('order_return_id', $record->order_return_id)->first();
                $allocated = BigDecimal::zero();
                foreach ($return?->refundAllocations()->where('allegro_payment_refund_id', '!=', $refund->id)->get() ?? [] as $allocation) {
                    $allocated = $allocated->plus($allocation->products_value);
                }
                if (! $return || app(OrderReturnRefundService::class)->identity($return) !== $record->identity()
                    || $return->currency !== $record->currency || $allocated->plus($record->products_value)->isGreaterThan($return->total_value)
                    || ($current && ($current->products_value !== $record->products_value || $current->delivery_value !== $record->delivery_value || $current->currency !== $record->currency))
                    || $refund->allocations()->where(fn ($q) => $q->whereNull('order_return_id')->orWhere('order_return_id', '!=', $record->order_return_id))->exists()) {
                    $state = 'review';
                } elseif (! $current) {
                    OrderReturnRefundAllocation::query()->create(['allegro_payment_refund_id' => $refund->id, 'order_return_id' => $return->id,
                        'return_number' => $record->return_number, 'currency' => $record->currency,
                        'products_value' => $record->products_value, 'delivery_value' => $record->delivery_value]);
                    $return->increment('lock_version');
                }
            }
            $changed = $record->state !== $state;
            $record->update(['state' => $state, 'allegro_payment_refund_id' => $refund->id,
                'error' => $state === 'review' ? 'Refundacja wymaga sprawdzenia; nie przypisano automatycznie środków.' : null,
                'next_check_at' => now()->addMinutes(5)]);
            if ($changed) {
                $this->event($record, $state);
            }
        }, 3);
    }

    private function matches(AllegroRefundCommand $command, array $data): bool
    {
        $items = $data['components']['items'];
        usort($items, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $expected = array_map(fn ($item) => ['id' => $item['id'], 'type' => 'AMOUNT', 'value' => $item['value']['amount']], $command->payload['lineItems'] ?? []);

        return $data['external_order_id'] === $command->external_order_id && $data['external_payment_id'] === $command->external_payment_id
            && $data['currency'] === $command->currency && $data['reason'] === $command->payload['reason']
            && $data['total_value'] === $command->total_value && $data['products_value'] === $command->products_value
            && $data['delivery_value'] === $command->delivery_value && $data['other_value'] === '0.00' && $items === $expected
            && ($data['seller_comment'] ?? '') === ($command->payload['sellerComment'] ?? '');
    }

    private function validateContext(AllegroRefundCommand $command, AllegroAccount $account, OrderReturn $return, array $fresh): void
    {
        if (! $account->isOperational() || ! $account->refund_write_enabled || $account->refund_write_identity !== $account->orderImportIdentity()
            || $account->refund_write_revision !== $command->configuration_revision || $fresh['account']->id !== $account->id
            || $command->context_hash !== $fresh['hash'] || $return->lock_version !== $command->return_version || $command->expires_at->isPast()
            || $fresh['ledger_hash'] !== $this->context->ledgerHash($command->identity(), $command->id)
            || $command->payload_hash !== AllegroRefundContextService::hash($command->payload)
            || $account->environment !== $command->environment || $account->allegro_user_id !== $command->seller_user_id
            || array_diff([AllegroAccount::PAYMENTS_READ_SCOPE, AllegroAccount::PAYMENTS_WRITE_SCOPE], $account->granted_scopes ?? []) !== []) {
            $this->context->invalid('Potwierdzenie wygasło lub dane zmieniły się. Otwórz formularz ponownie.');
        }
    }

    private function assertNoReservation(AllegroRefundCommand $command): void
    {
        if (AllegroRefundCommand::query()->where($command->identity())->whereKeyNot($command->id)->whereIn('state', AllegroRefundCommand::RESERVING_STATES)->exists()) {
            $this->context->invalid('Inny zwrot wpłaty tego zamówienia jest w toku lub wymaga sprawdzenia.');
        }
    }

    private function transition(int $id, array $from, string $state, string $error): void
    {
        DB::transaction(function () use ($id, $from, $state, $error): void {
            $record = AllegroRefundCommand::query()->lockForUpdate()->find($id);
            if ($record && in_array($record->state, $from, true)) {
                $record->update(['state' => $state, 'error' => $error, 'next_check_at' => $record->external_refund_id ? now()->addMinutes(5) : null]);
                $this->event($record, $state);
            }
        }, 3);
    }

    private function event(AllegroRefundCommand $command, string $state): void
    {
        OrderReturn::query()->find($command->order_return_id)?->events()->create(['type' => 'allegro_refund_command', 'status' => $command->stateLabel(), 'observed_at' => now(),
            'data' => ['command_id' => $command->command_id, 'state' => $state, 'total_value' => $command->total_value, 'currency' => $command->currency]]);
    }
}
