<?php

namespace Modules\Integrations\Prestashop\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\OrderStatusSetting;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopOrderStatusJob;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopOutboundStatusMapping;

class DispatchPrestashopStatusSync
{
    public function handle(OrderStatusChanged $event): void
    {
        if (str_starts_with($event->source, 'prestashop')) {
            return;
        }

        $link = PrestashopOrderLink::query()
            ->with('account')
            ->where('order_id', $event->order->getKey())
            ->first();

        if (! $link) {
            return;
        }

        $link->forceFill([
            'pending_outbound_state_id' => null,
            'pending_outbound_event_id' => null,
            'pending_outbound_since' => null,
        ])->save();

        if (! $link->account?->is_active) {
            return;
        }

        $statusSettingId = OrderStatusSetting::query()
            ->where('status', $event->newStatus)
            ->value('id');

        if (! $statusSettingId) {
            return;
        }

        $mapping = PrestashopOutboundStatusMapping::query()
            ->where('prestashop_account_id', $link->prestashop_account_id)
            ->where('order_status_setting_id', $statusSettingId)
            ->first();

        if (! $mapping) {
            return;
        }

        $targetIsAvailable = $link->account->orderStates()
            ->where('external_id', $mapping->external_order_state_id)
            ->where('is_available', true)
            ->where('deleted', false)
            ->exists();

        if (! $targetIsAvailable) {
            return;
        }

        $link->forceFill([
            'pending_outbound_state_id' => $mapping->external_order_state_id,
            'pending_outbound_event_id' => $event->eventId,
            'pending_outbound_since' => now(),
        ])->save();

        SyncPrestashopOrderStatusJob::dispatch(
            (int) $event->order->getKey(),
            $event->newStatus,
            $event->eventId,
        );
    }
}
