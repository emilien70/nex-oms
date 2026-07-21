<?php

namespace Modules\Automation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationRunner;

class ExecuteAutomationRunJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public AutomationRun $run,
        public int $position,
    ) {
        $this->onQueue('automation');
    }

    public function uniqueId(): string
    {
        return $this->run->id.':'.$this->position;
    }

    public function handle(AutomationRunner $runner): void
    {
        $runner->execute($this->run, $this->position);
    }
}
