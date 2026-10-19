<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopOutboundStatusMapping;
use Throwable;

class PrestashopOutboundStatusSyncService
{
    public function __construct(private readonly PrestashopClient $client) {}

    public function sync(int $orderId, string $expectedOmsStatus, string $eventId): void
    {
        $link = PrestashopOrderLink::query()
            ->with('account')
            ->where('order_id', $orderId)
            ->first();
        $order = Order::query()->find($orderId);

        if (! $order || $order->status !== $expectedOmsStatus) {
            $this->clearPendingForEvent($link, $eventId);

            return;
        }

        $account = $link?->account;

        if (! $link || ! $account?->is_active || ! $account->hasCompleteCredentials()) {
            $this->clearPendingForEvent($link, $eventId);

            return;
        }

        $statusSettingId = OrderStatusSetting::query()
            ->where('status', $expectedOmsStatus)
            ->value('id');
        $mapping = $statusSettingId
            ? PrestashopOutboundStatusMapping::query()
                ->where('prestashop_account_id', $account->getKey())
                ->where('order_status_setting_id', $statusSettingId)
                ->first()
            : null;

        if (! $mapping) {
            $this->clearPendingForEvent($link, $eventId);

            return;
        }

        $targetState = $account->orderStates()
            ->where('external_id', $mapping->external_order_state_id)
            ->where('is_available', true)
            ->where('deleted', false)
            ->first();

        if (! $targetState) {
            throw new PrestashopApiException(
                'Mapowanie wskazuje status PrestaShop, który nie jest już dostępny.',
                422,
            );
        }

        $currentStateId = $this->client->orderCurrentState($account, $link->external_order_id);

        if ($currentStateId === $targetState->external_id) {
            $this->markSynchronized(
                $link,
                $targetState->external_id,
                $targetState->name,
                $eventId,
                true,
            );

            return;
        }

        if (Order::query()->whereKey($orderId)->value('status') !== $expectedOmsStatus) {
            $this->clearPendingForEvent($link, $eventId);

            return;
        }

        $historyId = $this->client->createOrderHistory(
            $account,
            $link->external_order_id,
            $targetState->external_id,
            $account->notify_customer_on_status_change,
        );

        $this->markSynchronized(
            $link,
            $targetState->external_id,
            $targetState->name,
            $eventId,
            false,
            $historyId,
            $account->notify_customer_on_status_change,
        );
    }

    public function recordFailure(
        int $orderId,
        string $expectedOmsStatus,
        string $eventId,
        Throwable $exception,
    ): void {
        $link = PrestashopOrderLink::query()
            ->where('order_id', $orderId)
            ->first();
        $this->clearPendingForEvent($link, $eventId);
        $order = Order::query()->find($orderId);

        if (
            ! $order
            || ! $link
        ) {
            return;
        }

        if ($this->eventWasRecorded($order, 'prestashop_status_export_failed', $eventId)) {
            return;
        }

        $message = mb_substr(trim($exception->getMessage()), 0, 900);

        $order->events()->create([
            'event_type' => 'prestashop_status_export_failed',
            'title' => 'Nie udało się przekazać statusu do PrestaShop',
            'description' => $message !== ''
                ? $message
                : 'Wystąpił nieoczekiwany błąd synchronizacji statusu.',
            'payload' => [
                'event_id' => $eventId,
                'oms_status' => $expectedOmsStatus,
                'status_code' => $exception instanceof PrestashopApiException
                    ? $exception->statusCode
                    : null,
            ],
        ]);
    }

    private function markSynchronized(
        PrestashopOrderLink $link,
        int $stateId,
        string $stateName,
        string $eventId,
        bool $alreadyCurrent,
        ?int $historyId = null,
        bool $customerNotified = false,
    ): void {
        DB::transaction(function () use (
            $link,
            $stateId,
            $stateName,
            $eventId,
            $alreadyCurrent,
            $historyId,
            $customerNotified,
        ): void {
            $managedLink = PrestashopOrderLink::query()
                ->whereKey($link->getKey())
                ->lockForUpdate()
                ->first();

            if (! $managedLink) {
                return;
            }

            $previousStateId = $managedLink->external_order_state_id;
            $managedLink->forceFill([
                'external_order_state_id' => $stateId,
                'external_order_state_name' => $stateName,
                'external_updated_at' => now(),
                'pending_outbound_state_id' => $managedLink->pending_outbound_event_id === $eventId
                    ? null
                    : $managedLink->pending_outbound_state_id,
                'pending_outbound_event_id' => $managedLink->pending_outbound_event_id === $eventId
                    ? null
                    : $managedLink->pending_outbound_event_id,
                'pending_outbound_since' => $managedLink->pending_outbound_event_id === $eventId
                    ? null
                    : $managedLink->pending_outbound_since,
            ])->save();

            $order = $managedLink->order;

            if (! $order || $this->eventWasRecorded($order, 'prestashop_status_exported', $eventId)) {
                return;
            }

            $order->events()->create([
                'event_type' => 'prestashop_status_exported',
                'title' => 'Przekazano status do PrestaShop',
                'description' => $alreadyCurrent
                    ? "Status w PrestaShop był już zgodny: {$stateName}."
                    : "Ustawiono status PrestaShop: {$stateName}.",
                'payload' => [
                    'event_id' => $eventId,
                    'previous_external_order_state_id' => $previousStateId,
                    'external_order_state_id' => $managedLink->external_order_state_id,
                    'external_order_state_name' => $stateName,
                    'order_history_id' => $historyId,
                    'already_current' => $alreadyCurrent,
                    'customer_notified' => $customerNotified,
                ],
            ]);
        }, 3);
    }

    private function eventWasRecorded(Order $order, string $eventType, string $eventId): bool
    {
        return $order->events()
            ->where('event_type', $eventType)
            ->get(['payload'])
            ->contains(fn ($event): bool => ($event->payload['event_id'] ?? null) === $eventId);
    }

    private function clearPendingForEvent(?PrestashopOrderLink $link, string $eventId): void
    {
        if (! $link || $link->pending_outbound_event_id !== $eventId) {
            return;
        }

        PrestashopOrderLink::query()
            ->whereKey($link->getKey())
            ->where('pending_outbound_event_id', $eventId)
            ->update([
                'pending_outbound_state_id' => null,
                'pending_outbound_event_id' => null,
                'pending_outbound_since' => null,
                'updated_at' => now(),
            ]);
    }
}
