<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderTrackingLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderScanController extends Controller
{
    public function __invoke(Request $request, OrderTrackingLookupService $trackingLookup): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ], [
            'code.required' => 'Nie odczytano numeru przesyłki.',
            'code.max' => 'Zeskanowany numer przesyłki jest za długi.',
        ]);

        $trackingNumber = trim($validated['code']);
        $orderIds = $trackingLookup->matchingOrderIds(Order::query(), $trackingNumber);

        if ($orderIds->isEmpty()) {
            return response()->json([
                'message' => 'Nie znaleziono zamówienia dla numeru przesyłki: '.$trackingNumber.'.',
            ], 404);
        }

        if ($orderIds->count() > 1) {
            return response()->json([
                'message' => 'Numer przesyłki jest przypisany do więcej niż jednego zamówienia.',
            ], 409);
        }

        return response()->json([
            'order_url' => route('orders.show', $orderIds->first()),
        ]);
    }
}
