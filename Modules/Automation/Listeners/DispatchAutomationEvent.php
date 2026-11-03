<?php

namespace Modules\Automation\Listeners;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Events\ReturnParcelStatusChanged;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Automation\Models\AutomationRule;

class DispatchAutomationEvent
{
    public function handle(object $event): void
    {
        if (! method_exists($event, 'name') || ! method_exists($event, 'payload')) {
            return;
        }

        if ($event instanceof ReturnParcelStatusChanged && ! $event->parcel->orderReturn->order_id) {
            return;
        }

        if (($event instanceof OrderCreated || $event instanceof OrderDataCompleted || $event instanceof ReturnParcelStatusChanged)
            && ! AutomationRule::query()->where('trigger', $event->name())->where('is_active', true)->exists()) {
            return;
        }

        EvaluateAutomationEventJob::dispatch($event->name(), $event->payload());
    }
}
