<?php

namespace Modules\Automation\Services;

use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Throwable;

class AutomationRunner
{
    public function __construct(private readonly AutomationActionExecutor $actionExecutor) {}

    public function execute(AutomationRun $run, int $position): void
    {
        $run = $run->fresh(['order', 'steps']);

        if (! $run || ! $run->order || $run->isFinished()) {
            return;
        }

        $actions = $run->rule_snapshot['actions'] ?? [];
        $hadErrors = $run->steps->contains('status', 'failed');

        $run->update([
            'status' => AutomationRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
        ]);

        for ($index = $position; $index < count($actions); $index++) {
            $action = $actions[$index];
            $step = AutomationRunStep::query()->firstOrCreate(
                ['automation_run_id' => $run->id, 'position' => $index],
                [
                    'action_type' => $action['type'],
                    'status' => 'queued',
                    'configuration' => $action['configuration'] ?? [],
                ],
            );

            if ($step->status === 'completed') {
                continue;
            }

            if ($action['type'] === AutomationCatalog::ACTION_DELAY) {
                $minutes = max(1, min(86400, (int) data_get($action, 'configuration.minutes', 1)));

                $step->update([
                    'status' => 'completed',
                    'started_at' => now(),
                    'finished_at' => now(),
                    'output' => ['resume_at' => now()->addMinutes($minutes)->toIso8601String()],
                ]);

                $run->update(['status' => AutomationRun::STATUS_WAITING]);
                ExecuteAutomationRunJob::dispatch($run, $index + 1)->delay(now()->addMinutes($minutes));

                return;
            }

            $step->update(['status' => 'running', 'started_at' => now()]);

            try {
                $output = $this->actionExecutor->execute(
                    $run,
                    $action['type'],
                    $action['configuration'] ?? [],
                );

                $step->update([
                    'status' => 'completed',
                    'output' => $output,
                    'finished_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $hadErrors = true;
                $step->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);

                if ((bool) ($action['stop_on_error'] ?? true)) {
                    $this->finishFailed($run, $exception->getMessage());

                    return;
                }
            }
        }

        $this->finishCompleted($run, $hadErrors);
    }

    private function finishCompleted(AutomationRun $run, bool $withErrors): void
    {
        $run->update([
            'status' => $withErrors
                ? AutomationRun::STATUS_COMPLETED_WITH_ERRORS
                : AutomationRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);

        $run->order->events()->create([
            'event_type' => 'automation_completed',
            'title' => html_entity_decode('Automatyczna akcja wykonana', ENT_QUOTES, 'UTF-8'),
            'description' => (string) data_get($run->rule_snapshot, 'name', 'Automatyczna akcja'),
            'payload' => [
                'automation_run_id' => $run->id,
                'automation_rule_id' => $run->automation_rule_id,
                'event_name' => $run->event_name,
                'status' => $run->status,
            ],
        ]);
    }

    private function finishFailed(AutomationRun $run, string $message): void
    {
        $run->update([
            'status' => AutomationRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $run->order->events()->create([
            'event_type' => 'automation_failed',
            'title' => html_entity_decode('B&#322;&#261;d automatycznej akcji', ENT_QUOTES, 'UTF-8'),
            'description' => (string) data_get($run->rule_snapshot, 'name', 'Automatyczna akcja').': '.$message,
            'payload' => [
                'automation_run_id' => $run->id,
                'automation_rule_id' => $run->automation_rule_id,
                'event_name' => $run->event_name,
            ],
        ]);
    }
}
