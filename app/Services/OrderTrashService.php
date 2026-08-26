<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderTrashService
{
    public function restoreMany(array $orderIds): void
    {
        DB::transaction(function () use ($orderIds): void {
            Order::onlyTrashed()
                ->whereIn('id', $orderIds)
                ->get()
                ->each(function (Order $order): void {
                    $order->restore();

                    $order->events()->create([
                        'event_type' => 'order_restored',
                        'title' => html_entity_decode('Zam&oacute;wienie przywr&oacute;cone', ENT_QUOTES, 'UTF-8'),
                        'description' => html_entity_decode('Przywr&oacute;cono zam&oacute;wienie do statusu ', ENT_QUOTES, 'UTF-8').$order->statusLabel(),
                        'payload' => [
                            'status' => $order->status,
                        ],
                    ]);
                });
        });
    }

    public function forceDeleteMany(array $orderIds): void
    {
        DB::transaction(function () use ($orderIds): void {
            Order::onlyTrashed()
                ->whereIn('id', $orderIds)
                ->get()
                ->each(fn (Order $order) => $order->forceDelete());
        });
    }
}
