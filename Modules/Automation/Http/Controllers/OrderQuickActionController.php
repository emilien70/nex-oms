<?php

namespace Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Automation\Jobs\EvaluateCustomAutomationEventJob;
use Modules\Automation\Models\AutomationCustomEvent;
use Modules\Automation\Models\AutomationCustomEventInvocation;
use Throwable;

class OrderQuickActionController extends Controller
{
    public function store(Request $request, Order $order, AutomationCustomEvent $customEvent): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'confirmed' => ['sometimes', 'boolean'],
        ]);
        $requestId = strtolower($data['request_id']);

        $invocation = DB::transaction(function () use ($order, $customEvent, $request, $requestId): AutomationCustomEventInvocation {
            $event = AutomationCustomEvent::query()->lockForUpdate()->findOrFail($customEvent->id);
            $currentOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $invocation = AutomationCustomEventInvocation::query()->find($requestId);

            if ($invocation === null) {
                abort_unless($event->availableFor($currentOrder), 409, 'Ta szybka akcja nie jest dostępna dla tego zamówienia.');
                abort_if($event->confirmation_required && ! $request->boolean('confirmed'), 422, 'Potwierdź uruchomienie szybkiej akcji.');
                $recent = AutomationCustomEventInvocation::query()
                    ->where('order_id', $currentOrder->id)->where('automation_custom_event_id', $event->id)
                    ->where(function ($query): void {
                        $query->where('created_at', '>=', now()->subSeconds(3))
                            ->orWhere(fn ($pending) => $pending->where('status', AutomationCustomEventInvocation::PENDING)
                                ->where('created_at', '>=', now()->subMinutes(2)));
                    })->exists();
                abort_if($recent, 409, 'Ta szybka akcja jest już sprawdzana albo została przed chwilą zlecona.');

                $invocation = AutomationCustomEventInvocation::query()->firstOrCreate(['id' => $requestId], [
                    'order_id' => $order->id,
                    'automation_custom_event_id' => $event->id,
                    'event_label' => $event->name,
                    'status' => AutomationCustomEventInvocation::PENDING,
                ]);
            }

            abort_if($invocation->order_id !== $order->id || $invocation->automation_custom_event_id !== $event->id, 409);

            return $invocation;
        }, attempts: 3);

        if ($invocation->wasRecentlyCreated) {
            try {
                EvaluateCustomAutomationEventJob::dispatch($invocation->id);
            } catch (Throwable $exception) {
                report($exception);
                AutomationCustomEventInvocation::query()->whereKey($invocation->id)
                    ->where('status', AutomationCustomEventInvocation::PENDING)
                    ->update(['status' => AutomationCustomEventInvocation::FAILED]);
            }
        }

        return $this->result($invocation->refresh());
    }

    public function show(Order $order, AutomationCustomEventInvocation $invocation): JsonResponse
    {
        abort_unless($invocation->order_id === $order->id, 404);

        return $this->result($invocation);
    }

    private function result(AutomationCustomEventInvocation $invocation): JsonResponse
    {
        return response()->json($invocation->result() + [
            'status_url' => route('orders.quick-actions.show', ['order' => $invocation->order_id, 'invocation' => $invocation]),
        ], $invocation->status === AutomationCustomEventInvocation::PENDING ? 202 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
