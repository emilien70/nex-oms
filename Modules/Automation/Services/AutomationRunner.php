<?php

namespace Modules\Automation\Services;

use Illuminate\Support\Str;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRun;
use Throwable;

class AutomationRunner
{
    public function __construct(
        private readonly AutomationActionExecutor $actionExecutor,
        private readonly AutomationExecutionState $execution,
    ) {}

    public function execute(AutomationRun $run, int $position, ?string $token = null): void
    {
        $token ??= (string) Str::uuid();
        $run = $this->execution->claim($run, $position, $token);
        if (! $run) {
            return;
        }

        $actions = $run->rule_snapshot['actions'] ?? [];
        $hadErrors = $run->steps->contains('status', 'failed');

        for ($index = $position; $index < count($actions); $index++) {
            $action = $actions[$index];
            $step = $this->execution->startStep($run, $token, $index, $action);
            if (! $step) {
                $this->execution->interrupt($run->id, $token);

                return;
            }
            if (in_array($step->status, ['completed', 'failed'], true)) {
                continue;
            }

            if ($action['type'] === AutomationCatalog::ACTION_DELAY) {
                $minutes = max(1, min(86400, (int) data_get($action, 'configuration.minutes', 1)));
                $resumeAt = now()->addMinutes($minutes);
                if ($this->execution->wait($run, $token, $step, $resumeAt)) {
                    ExecuteAutomationRunJob::dispatch($run, $index + 1)->delay($resumeAt)->afterCommit();
                }

                return;
            }

            try {
                $output = $this->actionExecutor->execute(
                    $run,
                    $step,
                    $action['type'],
                    $action['configuration'] ?? [],
                );
            } catch (Throwable $exception) {
                if (! $this->execution->recordStep($run, $token, $step, null, $exception->getMessage())) {
                    $this->execution->interrupt($run->id, $token);

                    return;
                }
                $hadErrors = true;
                if ((bool) ($action['stop_on_error'] ?? true)) {
                    $this->execution->finish($run, $token, true, $exception->getMessage());

                    return;
                }

                continue;
            }

            // Persist the result separately: a failed write must never repeat the external action.
            if (! $this->execution->recordStep($run, $token, $step, $output, null)) {
                $this->execution->interrupt($run->id, $token);

                return;
            }
        }

        $this->execution->finish($run, $token, $hadErrors);
    }
}
