<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\OrderItem;
use Closure;
use Illuminate\Http\Request;
use Modules\Shipments\Models\Shipment;
use Symfony\Component\HttpFoundation\Response;

class RejectArchivedOrderChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('orders.archive.restore')) {
            return $next($request);
        }

        if (! $request->isMethodSafe() || $request->routeIs('orders.edit', 'orders.state', 'orders.shipments.form', 'shipments.label')) {
            $order = $request->route('order');
            $shipment = $request->route('shipment');
            $item = $request->route('orderItem');
            if (! $order instanceof Order && $shipment instanceof Shipment) {
                $order = $shipment->order;
            } elseif (! $order instanceof Order && $item instanceof OrderItem) {
                $order = $item->order;
            }

            abort_if($order instanceof Order && $order->isArchived(), 409, 'Przywróć zamówienie z archiwum, aby je obsłużyć.');
        }

        return $next($request);
    }
}
