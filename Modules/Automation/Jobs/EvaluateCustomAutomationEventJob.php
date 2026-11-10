<?php

namespace Modules\Automation\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Automation\Models\AutomationCustomEvent;
use Modules\Automation\Models\AutomationCustomEventInvocation;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationEngine;
use Throwable;

class EvaluateCustomAutomationEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public array $backoff = [5, 15, 30];

    public function __construct(public string $invocationId)
    {
        $this->onQueue('automation');
    }

    public function uniqueId(): string
    {
        return $this->invocationId;
    }

    public function handle(AutomationEngine $engine): void
    {
        $invocation = AutomationCustomEventInvocation::query()->find($this->invocationId);
        if ($invocation?->status !== AutomationCustomEventInvocation::PENDING) {
            return;
        }

        $order = Order::query()->find($invocation->order_id);
        $event = AutomationCustomEvent::query()->find($invocation->automation_custom_event_id);
        if (! $order || ! $event?->availableFor($order)) {
            $invocation->update(['status' => AutomationCustomEventInvocation::UNAVAILABLE]);

            return;
        }

        $engine->evaluate($event->triggerKey(), [
            'event_id' => $invocation->id,
            'order_id' => $order->id,
            'source' => 'manual_quick_action',
            'custom_event_label' => $invocation->event_label,
        ]);

        $invocation->update([
            'status' => AutomationCustomEventInvocation::CHECKED,
            'run_count' => AutomationRun::query()->where('order_id', $order->id)->where('event_id', $invocation->id)->count(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AutomationCustomEventInvocation::query()
            ->whereKey($this->invocationId)
            ->where('status', AutomationCustomEventInvocation::PENDING)
            ->update(['status' => AutomationCustomEventInvocation::FAILED]);
    }
}
