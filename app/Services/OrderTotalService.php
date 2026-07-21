<?php

namespace App\Services;

use App\Models\Order;

class OrderTotalService
{
    public function recalculate(Order $order): float
    {
        $itemsTotal = (float) $order->items()->sum('total_price_gross');
        $totalGross = round($itemsTotal + (float) $order->delivery_cost_gross, 2);

        $order->update([
            'total_gross' => $totalGross,
        ]);

        return $totalGross;
    }
}
