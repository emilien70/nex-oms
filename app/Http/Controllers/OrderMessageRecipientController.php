<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderMessageRecipientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderMessageRecipientController extends Controller
{
    public function show(Request $request, Order $order, OrderMessageRecipientService $recipients): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['sms', 'email'])]]);
        $order->assertPurchaseReady();

        return response()->json([
            'recipient' => $recipients->recipient($order, $data['channel']),
            'recipient_token' => $recipients->token($order, $data['channel']),
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
