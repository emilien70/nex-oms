<?php

namespace App\Services;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderStatusService
{
    public function change(Order $order, string $newStatus, string $source = 'manual'): bool
    {
        if ($order->status === $newStatus) {
            return false;
        }

        return DB::transaction(function () use ($order, $newStatus, $source): bool {
            $oldStatus = $order->status;
            $oldLabel = $order->statusLabel();

            $order->update([
                'status' => $newStatus,
                'status_changed_at' => now(),
            ]);

            $order->events()->create([
                'event_type' => 'order_status_changed',
                'title' => html_entity_decode('Status zam&oacute;wienia zmieniony', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Zmieniono status z ', ENT_QUOTES, 'UTF-8').$oldLabel.' na '.$order->statusLabel(),
                'payload' => [
                    'old_status' => $oldStatus,
                    'new_status' => $order->status,
                    'source' => $source,
                ],
            ]);

            OrderStatusChanged::dispatch($order, $oldStatus, $order->status, $source);

            return true;
        });
    }
}
