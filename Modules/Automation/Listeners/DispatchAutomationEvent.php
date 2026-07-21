<?php

namespace Modules\Automation\Listeners;

use Modules\Automation\Jobs\EvaluateAutomationEventJob;

class DispatchAutomationEvent
{
    public function handle(object $event): void
    {
        if (! method_exists($event, 'name') || ! method_exists($event, 'payload')) {
            return;
        }

        EvaluateAutomationEventJob::dispatch($event->name(), $event->payload());
    }
}
