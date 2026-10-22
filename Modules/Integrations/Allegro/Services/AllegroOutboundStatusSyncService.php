<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\SyncAllegroOrderStatusJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroOutboundStatusMapping;
use Throwable;

class AllegroOutboundStatusSyncService
{
    public function __construct(private readonly AllegroClient $client, private readonly AllegroOrderSyncService $orders) {}

    public function enqueue(int $orderId, string $expectedStatus, string $eventId): bool
    {
        return DB::transaction(function () use ($orderId, $expectedStatus, $eventId): bool {
            $link = AllegroOrderLink::query()->where('order_id', $orderId)->lockForUpdate()->first();
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $link || ! $order || $order->status !== $expectedStatus || $link->status_sync_event_id === $eventId) {
                return false;
            }
            // Every local status change invalidates an older queued or failed export.
            $link->update([
                'status_sync_event_id' => null, 'status_sync_oms_status' => $expectedStatus,
                'status_sync_target' => null, 'status_sync_state' => null, 'status_sync_error' => null,
            ]);
            $account = $link->account;
            if (! $this->eligible($account, $link)) {
                return false;
            }
            $target = $this->target($account, $expectedStatus);
            if (! $target) {
                return false;
            }
            $link->update([
                'status_sync_event_id' => $eventId, 'status_sync_target' => $target->value, 'status_sync_state' => 'pending',
            ]);
            SyncAllegroOrderStatusJob::dispatch($orderId, $expectedStatus, $eventId, $account->id, (int) $account->status_sync_revision)
                ->afterCommit();

            return true;
        }, 3);
    }

    public function retry(int $orderId, string $eventId): bool
    {
        return DB::transaction(function () use ($orderId, $eventId): bool {
            $link = AllegroOrderLink::query()->where('order_id', $orderId)->lockForUpdate()->first();
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $link || ! $order || $link->status_sync_state !== 'failed'
                || $order->status !== $link->status_sync_oms_status || ! $this->eligible($link->account, $link)
                || ! $this->target($link->account, $order->status)) {
                return false;
            }

            return $this->enqueue($orderId, $order->status, $eventId);
        }, 3);
    }

    public function sync(SyncAllegroOrderStatusJob $job): void
    {
        $context = $this->context($job);
        if (! $context) {
            $this->skip($job);

            return;
        }
        [$order, $link, $account, $target] = $context;
        $raw = $this->client->order($account, $link->external_order_id);
        if (strtolower((string) ($raw['id'] ?? '')) !== strtolower($link->external_order_id)) {
            throw new AllegroApiException('Allegro zwróciło inne zamówienie. Przekazanie statusu zostało zatrzymane.', 422);
        }
        if (($raw['status'] ?? null) === 'CANCELLED') {
            $this->orders->cancel($link);
            $this->skip($job);

            return;
        }
        if (($raw['status'] ?? null) !== 'READY_FOR_PROCESSING' || data_get($raw, 'fulfillment.provider.id') !== 'SELLER') {
            throw new AllegroApiException('Zamówienie Allegro nie jest gotowe do realizacji przez sprzedawcę.', 422);
        }
        $context = $this->context($job);
        if (! $context) {
            $this->skip($job);

            return;
        }
        [$order, $link, $account, $target] = $context;
        $alreadyCurrent = data_get($raw, 'fulfillment.status') === $target->value;
        if (! $alreadyCurrent) {
            $revision = $raw['revision'] ?? null;
            if (! is_string($revision) || trim($revision) === '') {
                throw new AllegroApiException('Brakuje aktualnej wersji zamówienia Allegro. Status nie został przekazany.', 422);
            }
            $this->client->updateFulfillment($account, $link->external_order_id, $target, $revision);
        }

        DB::transaction(function () use ($job, $target, $alreadyCurrent): void {
            $link = AllegroOrderLink::query()->where('order_id', $job->orderId)->lockForUpdate()->first();
            $order = Order::query()->lockForUpdate()->find($job->orderId);
            if (! $link || ! $order || ! $this->context($job)) {
                $this->skip($job);

                return;
            }
            $link->update([
                'fulfillment_status' => $target->value, 'status_sync_state' => 'synced',
                'status_sync_error' => null, 'status_synced_at' => now(),
            ]);
            $order->events()->create([
                'event_type' => 'allegro_status_exported', 'title' => 'Status zamówienia przekazany do Allegro',
                'description' => $alreadyCurrent ? 'Allegro ma już status: '.$target->label().'.' : 'Status Allegro: '.$target->label().'.',
                'payload' => ['event_id' => $job->eventId, 'oms_status' => $job->expectedOmsStatus,
                    'allegro_status' => $target->value, 'already_current' => $alreadyCurrent],
            ]);
        }, 3);
    }

    public function recordFailure(SyncAllegroOrderStatusJob $job, Throwable $exception): void
    {
        DB::transaction(function () use ($job, $exception): void {
            $link = AllegroOrderLink::query()->where('order_id', $job->orderId)->lockForUpdate()->first();
            $order = Order::query()->lockForUpdate()->find($job->orderId);
            if (! $link || ! $order || ! $this->context($job)) {
                $this->skip($job);

                return;
            }
            $message = $exception instanceof AllegroApiException
                ? mb_substr($exception->getMessage(), 0, 900) : 'Wystąpił błąd przekazywania statusu do Allegro. Spróbuj ponownie.';
            $link->update(['status_sync_state' => 'failed', 'status_sync_error' => $message]);
            $order->events()->create([
                'event_type' => 'allegro_status_export_failed', 'title' => 'Nie udało się przekazać statusu do Allegro',
                'description' => $message,
                'payload' => ['event_id' => $job->eventId, 'oms_status' => $job->expectedOmsStatus,
                    'allegro_status' => $link->status_sync_target],
            ]);
        }, 3);
    }

    private function context(SyncAllegroOrderStatusJob $job): ?array
    {
        $order = Order::query()->find($job->orderId);
        $link = AllegroOrderLink::query()->with('account')->where('order_id', $job->orderId)->first();
        $account = $link?->account;
        if (! $order || $order->status !== $job->expectedOmsStatus || ! $link
            || $link->status_sync_event_id !== $job->eventId || $link->status_sync_state !== 'pending'
            || $link->allegro_account_id !== $job->accountId || ! $this->eligible($account, $link)
            || (int) $account->status_sync_revision !== $job->configurationRevision) {
            return null;
        }
        $target = $this->target($account, $job->expectedOmsStatus);

        return $target && $target->value === $link->status_sync_target ? [$order, $link, $account, $target] : null;
    }

    private function eligible(?AllegroAccount $account, AllegroOrderLink $link): bool
    {
        return $account && $account->status_sync_enabled && $account->isOperational() && $link->isReady()
            && $link->external_status === 'READY_FOR_PROCESSING'
            && $account->status_sync_identity === $account->orderImportIdentity()
            && $link->environment === $account->environment && $link->seller_user_id === $account->allegro_user_id;
    }

    private function target(AllegroAccount $account, string $status): ?AllegroFulfillmentStatus
    {
        $id = OrderStatusSetting::query()->where('status', $status)->value('id');

        return $id ? AllegroOutboundStatusMapping::query()->where('allegro_account_id', $account->id)
            ->where('environment', $account->environment)->where('seller_user_id', $account->allegro_user_id)
            ->where('order_status_setting_id', $id)->first()?->allegro_status : null;
    }

    private function skip(SyncAllegroOrderStatusJob $job): void
    {
        AllegroOrderLink::query()->where('order_id', $job->orderId)->where('status_sync_event_id', $job->eventId)
            ->where('status_sync_state', 'pending')->update(['status_sync_state' => 'skipped', 'status_sync_error' => null]);
    }
}
