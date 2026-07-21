<?php

namespace Modules\Automation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Automation\Services\AutomationEngine;

class EvaluateAutomationEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public array $backoff = [5, 15, 30];

    public function __construct(
        public string $eventName,
        public array $eventPayload,
    ) {
        $this->onQueue('automation');
    }

    public function uniqueId(): string
    {
        return $this->eventName.':'.($this->eventPayload['event_id'] ?? sha1(json_encode($this->eventPayload)));
    }

    public function handle(AutomationEngine $engine): void
    {
        $engine->evaluate($this->eventName, $this->eventPayload);
    }
}
