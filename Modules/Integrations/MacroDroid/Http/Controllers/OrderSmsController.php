<?php

namespace Modules\Integrations\MacroDroid\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\MacroDroid\Models\SmsTemplate;
use Modules\Integrations\MacroDroid\Services\SmsGatewayService;
use Modules\Integrations\MacroDroid\Services\SmsPreparationService;
use Modules\Integrations\MacroDroid\Services\SmsSegments;

class OrderSmsController extends Controller
{
    public function preview(Request $request, SmsPreparationService $preparation, SmsSegments $segments)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:12000'], 'order_id' => ['nullable', 'integer', 'exists:orders,id']]);
        $preparation->validateTemplate($data['body']);
        $result = ! empty($data['order_id'])
            ? $preparation->preview(Order::query()->findOrFail($data['order_id']), $data['body'])
            : ['body' => $data['body'], ...$segments->measure($data['body'])];

        return response()->json($result + ['rendered' => ! empty($data['order_id'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Order $order, SmsPreparationService $preparation)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:12000'],
            'sms_template_id' => ['nullable', 'integer', 'exists:sms_templates,id'],
            'recipient' => ['required', 'string', 'max:32'], 'idempotency_key' => ['required', 'uuid']]);
        $template = isset($data['sms_template_id']) ? SmsTemplate::query()->findOrFail($data['sms_template_id']) : null;
        $messages = $preparation->prepare($order, $template, $data + [
            'recipient_customer' => false, 'recipient_phones' => [$data['recipient']],
            'actor_name' => $request->user()?->name ?? 'Operator NEX-OMS',
        ]);

        return response()->json(['message' => 'Dodano SMS do kolejki.', 'id' => $messages[0]->id], 201);
    }

    public function history(Order $order)
    {
        $messages = SmsMessage::query()->where('order_id', $order->id)->latest('id')->limit(25)->get();

        return response()->json(['html' => view('components.order-sms-history', compact('messages', 'order'))->render(),
            'pending' => $messages->contains(fn ($message) => ! $message->terminal())])->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Order $order, SmsMessage $smsMessage, SmsGatewayService $gateway)
    {
        abort_unless($smsMessage->order_id === $order->id, 404);
        $gateway->cancel($smsMessage);

        return response()->json(['ok' => true]);
    }
}
