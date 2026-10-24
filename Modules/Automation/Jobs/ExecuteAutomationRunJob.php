<?php

namespace Modules\Automation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationExecutionState;
use Modules\Automation\Services\AutomationRunner;
use Throwable;

class ExecuteAutomationRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public bool $failOnTimeout = true;

    public string $executionToken;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public AutomationRun $run,
        public int $position,
    ) {
        $this->onQueue('automation');
        $this->executionToken = (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return $this->run->id.':'.$this->position;
    }

    public function handle(AutomationRunner $runner): void
    {
        $runner->execute($this->run, $this->position, $this->executionToken ?? (string) Str::uuid());
    }

    public function failed(?Throwable $exception): void
    {
        if (isset($this->executionToken)) {
            app(AutomationExecutionState::class)->interrupt($this->run->id, $this->executionToken);
        }
    }
}
