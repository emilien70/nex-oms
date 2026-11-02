<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Models\Order;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyCommandUncertainException;
use Modules\Integrations\VonHalsky\Jobs\ProcessVonHalskyOrderCommandJob;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderCommand;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderLink;
use Throwable;

class VonHalskyOrderCommandService
{
    public function __construct(private readonly VonHalskyClient $client, private readonly VonHalskyStatusMappingService $mappings) {}

    public function enqueue(int $orderId, string $status, string $eventId): bool
    {
        if (! $this->mappings->ready()) {
            return false;
        }

        return DB::transaction(function () use ($orderId, $status, $eventId): bool {
            $context = $this->writeContext($orderId, $status);
            if (! $context || VonHalskyOrderCommand::query()->where('event_id', $eventId)->exists()) {
                return false;
            }
            [$account, $order, $link, $action] = $context;
            $key = $this->operationKey($link);
            $previous = VonHalskyOrderCommand::query()->where('operation_key', $key)->lockForUpdate()->first();
            if ($previous && $previous->state === 'queued'
                && ($previous->oms_status !== $status || $previous->configuration_revision !== $account->status_sync_revision)) {
                $this->finish($previous, 'skipped', 'stale_configuration', 'Zlecenie zastąpiono po zmianie statusu lub ustawień.');
                $previous = null;
            }
            if ($previous && (($previous->action === $action && $previous->state === 'succeeded')
                || ($previous->state === 'queued' && $previous->action === $action))) {
                return false;
            }
            $command = VonHalskyOrderCommand::query()->create([
                'von_halsky_account_id' => $account->id, 'order_id' => $order->id, 'order_link_id' => $link->id,
                'environment' => $link->environment, 'organization_id' => $link->organization_id, 'external_order_id' => $link->external_order_id,
                'event_id' => $eventId, 'operation_key' => $previous ? null : $key,
                'oms_status' => $status, 'oms_status_label' => $order->statusLabel(), 'action' => $action,
                'configuration_revision' => $account->status_sync_revision, 'connection_revision' => $account->connection_revision,
                'next_check_at' => now(),
            ]);
            if ($previous) {
                $this->finish($command, 'blocked', 'existing_operation', 'Zamówienie ma już potwierdzone albo nierozstrzygnięte zlecenie Von Halsky.');
            } else {
                $this->dispatch($command);
            }

            return true;
        }, 3);
    }

    public function retry(Order $order, int $id, int $version): void
    {
        if (! $this->mappings->ready()) {
            throw ValidationException::withMessages(['von_halsky_command' => 'Najpierw wykonaj migrację przekazywania statusów.']);
        }
        DB::transaction(function () use ($order, $id, $version): void {
            $currentOrder = Order::query()->find($order->id);
            $context = $currentOrder ? $this->writeContext($order->id, $currentOrder->status) : null;
            $old = VonHalskyOrderCommand::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($id);
            if (! $context || $old->state !== 'failed' || $old->retried_at || $old->lock_version !== $version) {
                throw ValidationException::withMessages(['von_halsky_command' => 'Nie można ponowić tego zlecenia. Odśwież zamówienie.']);
            }
            [$account, $currentOrder, $link, $action] = $context;
            if ($old->action !== $action || $old->von_halsky_account_id !== $account->id || $old->order_link_id !== $link->id
                || $old->environment !== $link->environment || $old->organization_id !== $link->organization_id
                || $old->external_order_id !== $link->external_order_id
                || VonHalskyOrderCommand::query()->where('operation_key', $this->operationKey($link))->exists()) {
                throw ValidationException::withMessages(['von_halsky_command' => 'Zlecenie nie odpowiada bieżącemu mapowaniu lub ma już aktywną próbę.']);
            }
            $old->forceFill(['retried_at' => now(), 'lock_version' => $old->lock_version + 1])->save();
            $next = $old->replicate(['command_id', 'sending_token', 'sending_at', 'submitted_at', 'completed_at', 'retried_at',
                'last_error', 'last_error_code', 'last_dispatched_at']);
            $next->forceFill(['retry_of_id' => $old->id, 'event_id' => (string) Str::uuid(), 'state' => 'queued', 'lock_version' => 0,
                'operation_key' => $this->operationKey($link), 'configuration_revision' => $account->status_sync_revision,
                'connection_revision' => $account->connection_revision, 'oms_status' => $currentOrder->status,
                'oms_status_label' => $currentOrder->statusLabel(), 'next_check_at' => now()])->save();
            $this->dispatch($next);
        }, 3);
    }

    public function dispatchDue(): int
    {
        if (! $this->mappings->ready()) {
            return 0;
        }
        $ids = VonHalskyOrderCommand::query()->where(function ($q): void {
            $q->where(fn ($q) => $q->whereIn('state', ['queued', 'pending', 'uncertain'])
                ->whereNotNull('next_check_at')->where('next_check_at', '<=', now()->format('Y-m-d H:i:s.u')))
                ->orWhere(fn ($q) => $q->where('state', 'sending')->where('sending_at', '<=', now()->subSeconds(180)->format('Y-m-d H:i:s.u')));
        })->where(fn ($q) => $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subSeconds(60)->format('Y-m-d H:i:s.u')))
            ->orderBy('next_check_at')->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $command = VonHalskyOrderCommand::query()->lockForUpdate()->find($id);
                if (! $command || ($command->last_dispatched_at && $command->last_dispatched_at->gt(now()->subSeconds(60)))) {
                    return 0;
                }
                $this->dispatch($command);

                return 1;
            });
        }

        return $count;
    }

    public function process(int $id): void
    {
        if (! $this->mappings->ready()) {
            return;
        }
        $command = VonHalskyOrderCommand::query()->find($id);
        if (! $command) {
            return;
        }
        if (in_array($command->state, ['pending', 'uncertain'], true) && $command->command_id) {
            $this->poll($command);

            return;
        }
        if ($command->state === 'sending' && $command->sending_at?->lte(now()->subSeconds(180))) {
            $this->recordFailure($id, new VonHalskyCommandUncertainException);

            return;
        }
        if ($command->state !== 'queued') {
            return;
        }
        $context = DB::transaction(fn () => $this->currentWrite($command));
        if (! $context) {
            $this->skip($id);

            return;
        }
        [$account, $order, $link] = $context;
        $raw = $this->client->order($account, $command->external_order_id);
        if ($error = $this->sourceError($command, $link, $order, $raw)) {
            DB::transaction(function () use ($id, $error): void {
                $current = VonHalskyOrderCommand::query()->lockForUpdate()->find($id);
                if ($current?->state === 'queued') {
                    $this->finish($current, 'blocked', $error[0], $error[1]);
                }
            });

            return;
        }
        if (! DB::transaction(fn () => $this->currentWrite($command))) {
            $this->skip($id);

            return;
        }
        $this->client->prepareOrderCommand($account);
        $sending = DB::transaction(function () use ($command): ?VonHalskyOrderCommand {
            if (! $this->currentWrite($command)) {
                return null;
            }
            $current = VonHalskyOrderCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($current->state !== 'queued') {
                return null;
            }
            $current->forceFill(['state' => 'sending', 'sending_token' => (string) Str::uuid(), 'sending_at' => now(),
                'next_check_at' => now()->addSeconds(180), 'lock_version' => $current->lock_version + 1])->save();

            return $current;
        }, 3);
        if (! $sending) {
            $this->skip($id);

            return;
        }
        try {
            $result = $this->client->orderCommand($account, $sending->external_order_id, $sending->action);
        } catch (VonHalskyCommandUncertainException $exception) {
            $this->uncertain($id, $exception->commandId);

            return;
        } catch (VonHalskyApiException $exception) {
            DB::transaction(function () use ($id, $exception): void {
                $current = VonHalskyOrderCommand::query()->lockForUpdate()->findOrFail($id);
                if ($current->state === 'sending') {
                    $this->finish($current, 'failed', 'request_rejected', mb_substr($exception->getMessage(), 0, 1000));
                }
            });

            return;
        }
        try {
            $this->receipt($id, $result);
        } catch (Throwable) {
            // The POST already happened. Preserve its ID for GET-only recovery.
            $this->uncertain($id, $result['commandId']);
        }
    }

    public function recordFailure(int $id, ?Throwable $exception): void
    {
        if (! $this->mappings->ready()) {
            return;
        }
        DB::transaction(function () use ($id, $exception): void {
            $command = VonHalskyOrderCommand::query()->lockForUpdate()->find($id);
            if (! $command) {
                return;
            }
            if ($command->state === 'sending') {
                $this->uncertain($id);
            } elseif ($command->state === 'queued') {
                $this->finish($command, 'failed', 'preflight_failed', $exception instanceof VonHalskyApiException
                    ? mb_substr($exception->getMessage(), 0, 1000) : 'Nie udało się sprawdzić zamówienia przed przekazaniem statusu.');
            } elseif (in_array($command->state, ['pending', 'uncertain'], true) && $command->command_id) {
                $command->forceFill(['last_error_code' => 'poll_failed', 'last_error' => $exception instanceof VonHalskyApiException
                    ? mb_substr($exception->getMessage(), 0, 1000) : 'Nie udało się sprawdzić wyniku polecenia. Operacja nie została ponowiona.',
                    'next_check_at' => now()->addSeconds(max(60, $exception instanceof VonHalskyApiException ? ($exception->retryAfter ?? 60) : 60)),
                    'lock_version' => $command->lock_version + 1])->save();
            }
        }, 3);
    }

    private function poll(VonHalskyOrderCommand $command): void
    {
        $account = VonHalskyAccount::query()->find($command->von_halsky_account_id);
        if (! $this->owns($account, $command)) {
            $this->recordFailure($command->id, new VonHalskyApiException('Nie można sprawdzić wyniku w bieżącym połączeniu Von Halsky.'));

            return;
        }
        $this->receipt($command->id, $this->client->orderCommandStatus($account, $command->command_id));
    }

    private function receipt(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            $command = VonHalskyOrderCommand::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($command->state, ['sending', 'pending', 'uncertain'], true)
                || ($command->command_id && strtolower($command->command_id) !== strtolower($data['commandId']))) {
                return;
            }
            $command->command_id = $data['commandId'];
            $command->submitted_at ??= now();
            if ($data['status'] === 'SUCCESS') {
                $this->finish($command, 'succeeded');
            } elseif ($data['status'] === 'FAILURE') {
                $this->finish($command, 'failed', 'command_failed', 'Von Halsky zakończył zlecenie niepowodzeniem.');
            } else {
                $command->forceFill(['state' => 'pending', 'last_error' => null, 'last_error_code' => null,
                    'next_check_at' => now()->addMinute(), 'lock_version' => $command->lock_version + 1])->save();
            }
        }, 3);
    }

    private function uncertain(int $id, ?string $commandId = null): void
    {
        DB::transaction(function () use ($id, $commandId): void {
            $command = VonHalskyOrderCommand::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($command->state, ['sending', 'pending', 'uncertain'], true)) {
                return;
            }
            $commandId ??= $command->command_id;
            if ($commandId && VonHalskyOrderCommand::query()->where('environment', $command->environment)
                ->where('organization_id', $command->organization_id)->where('command_id', $commandId)->where('id', '!=', $id)->exists()) {
                $commandId = null;
            }
            $changed = $command->state !== 'uncertain';
            $command->forceFill(['state' => 'uncertain', 'command_id' => $commandId,
                'last_error_code' => 'uncertain', 'last_error' => (new VonHalskyCommandUncertainException)->getMessage(),
                'next_check_at' => $commandId ? now()->addMinute() : null, 'lock_version' => $command->lock_version + 1])->save();
            if ($changed) {
                $this->event($command);
            }
        }, 3);
    }

    private function writeContext(int $orderId, string $status): ?array
    {
        $snapshot = VonHalskyOrderLink::query()->where('order_id', $orderId)->first();
        $account = $snapshot ? VonHalskyAccount::query()->lockForUpdate()->find($snapshot->von_halsky_account_id) : null;
        $order = Order::query()->lockForUpdate()->find($orderId);
        $link = $snapshot ? VonHalskyOrderLink::query()->lockForUpdate()->find($snapshot->id) : null;
        if (! $account || ! $order || ! $link || ! $account->status_sync_enabled || ! $this->owns($account, $link)
            || $link->von_halsky_account_id !== $account->id || $link->order_id !== $order->id
            || $order->source !== 'von_halsky' || $order->external_id !== $link->external_order_id || $order->status !== $status) {
            return null;
        }
        $action = $this->mappings->target($account, $status);

        return $action ? [$account, $order, $link, $action] : null;
    }

    private function currentWrite(VonHalskyOrderCommand $command): ?array
    {
        $context = $command->order_id ? $this->writeContext($command->order_id, $command->oms_status) : null;
        if (! $context) {
            return null;
        }
        [$account, , $link, $action] = $context;

        return $command->von_halsky_account_id === $account->id && $command->order_link_id === $link->id
            && $command->configuration_revision === $account->status_sync_revision && $command->connection_revision === $account->connection_revision
            && $command->environment === $link->environment && $command->organization_id === $link->organization_id
            && $command->external_order_id === $link->external_order_id && $command->action === $action ? $context : null;
    }

    private function owns(?VonHalskyAccount $account, VonHalskyOrderCommand|VonHalskyOrderLink $record): bool
    {
        return $account && $account->is_active && $account->last_test_status === 'success' && $account->hasCompleteCredentials()
            && $record->environment === $account->environment->value && $record->organization_id === $account->organization_id;
    }

    private function sourceError(VonHalskyOrderCommand $command, VonHalskyOrderLink $link, Order $order, array $raw): ?array
    {
        if (($raw['id'] ?? null) !== $command->external_order_id || ! is_string($raw['organizationId'] ?? null)
            || strtolower($raw['organizationId']) !== strtolower($command->organization_id)) {
            return ['identity_mismatch', 'Von Halsky zwrócił inne zamówienie lub organizację.'];
        }
        $payments = data_get($raw, 'paymentDetails.payments');
        if (! is_array($payments) || ! array_is_list($payments)) {
            return ['invalid_payment_data', 'Nieprawidłowe dane płatności w odpowiedzi Von Halsky.'];
        }
        if ($command->action === VonHalskyOrderAction::Cancel) {
            if ($payments !== [] || ! BigDecimal::of((string) $link->channel_paid_amount)->isZero()
                || ! BigDecimal::of((string) $order->paid_amount)->isZero() || $order->payment_status !== 'unpaid'
                || ($raw['status'] ?? null) !== 'CREATED') {
                return ['paid_cancellation_blocked', 'Nie można anulować opłaconego lub zaakceptowanego zamówienia przez mapowanie statusu. Sprawdź obsługę zwrotu w Von Halsky; OMS nie zlecił zwrotu pieniędzy.'];
            }
        } elseif (($raw['status'] ?? null) !== 'ACCEPTED' || $payments === []) {
            return ['not_ready', 'Zamówienie Von Halsky nie jest opłacone i zaakceptowane przez platformę.'];
        }

        return null;
    }

    private function finish(VonHalskyOrderCommand $command, string $state, ?string $code = null, ?string $error = null): void
    {
        $command->forceFill(['state' => $state, 'last_error_code' => $code, 'last_error' => $error,
            'completed_at' => now(), 'next_check_at' => null, 'lock_version' => $command->lock_version + 1,
            'operation_key' => $state === 'succeeded' ? $command->operation_key : null])->save();
        $this->event($command);
    }

    private function event(VonHalskyOrderCommand $command): void
    {
        if ($command->order_id && $order = Order::withTrashed()->find($command->order_id)) {
            $order->events()->create(['event_type' => 'von_halsky_command_'.$command->state,
                'title' => $command->state === 'succeeded' ? 'Przekazano status do Von Halsky' : 'Wynik przekazywania statusu do Von Halsky',
                'description' => $command->action->label().': '.$command->stateLabel().'.'.($command->last_error ? ' '.$command->last_error : ''),
                'payload' => ['operation_id' => $command->id, 'command_id' => $command->command_id,
                    'action' => $command->action->value, 'oms_status' => $command->oms_status, 'state' => $command->state]]);
        }
    }

    private function skip(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $command = VonHalskyOrderCommand::query()->lockForUpdate()->find($id);
            if ($command?->state === 'queued') {
                $this->finish($command, 'skipped', 'stale_configuration', 'Status, powiązanie zamówienia lub konfiguracja uległy zmianie.');
            }
        }, 3);
    }

    private function dispatch(VonHalskyOrderCommand $command): void
    {
        $command->forceFill(['last_dispatched_at' => now()])->save();
        $id = $command->id;
        DB::afterCommit(static fn () => ProcessVonHalskyOrderCommandJob::dispatch($id)->afterCommit());
    }

    private function operationKey(VonHalskyOrderLink $link): string
    {
        return hash('sha256', $link->environment.'|'.strtolower($link->organization_id).'|'.$link->external_order_id);
    }
}
