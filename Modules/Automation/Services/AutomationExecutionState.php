<?php

namespace Modules\Automation\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;

class AutomationExecutionState
{
    public const LEASE_SECONDS = 300;

    private function interruptionMessage(): string
    {
        return html_entity_decode('Przerwano wykonanie automatycznej akcji. Wynik rozpocz&#281;tego kroku jest niepewny. Sprawd&#378; jego rezultat przed ponowieniem; pozosta&#322;ych krok&oacute;w nie wykonano.', ENT_QUOTES, 'UTF-8');
    }

    public function claim(AutomationRun $run, int $position, string $token): ?AutomationRun
    {
        return DB::transaction(function () use ($run, $position, $token): ?AutomationRun {
            $managed = AutomationRun::query()->with('order', 'steps')->lockForUpdate()->find($run->id);

            if (! $managed?->order || $managed->isFinished() || $managed->execution_token !== null) {
                return null;
            }

            if ($managed->status === AutomationRun::STATUS_WAITING) {
                $delay = $managed->steps->last(fn ($step) => $step->action_type === AutomationCatalog::ACTION_DELAY
                    && $step->status === 'completed' && filled(data_get($step->output, 'resume_at')));
                if (! $delay || $position !== $delay->position + 1
                    || now()->lt(data_get($delay->output, 'resume_at'))) {
                    return null;
                }
            } elseif ($managed->status !== AutomationRun::STATUS_QUEUED || $position !== 0) {
                return null;
            }

            $managed->update([
                'status' => AutomationRun::STATUS_RUNNING,
                'started_at' => $managed->started_at ?: now(),
                'execution_token' => $token,
                'execution_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);

            return $managed;
        }, 3);
    }

    public function startStep(AutomationRun $run, string $token, int $position, array $action): ?AutomationRunStep
    {
        return DB::transaction(function () use ($run, $token, $position, $action): ?AutomationRunStep {
            $managed = $this->owned($run->id, $token);
            if (! $managed) {
                return null;
            }

            $step = $managed->steps()->firstOrCreate(['position' => $position], [
                'action_type' => $action['type'],
                'status' => 'queued',
                'configuration' => $action['configuration'] ?? [],
            ]);
            if ($step->status === 'queued') {
                $step->update(['status' => 'running', 'started_at' => now()]);
                $managed->update(['execution_expires_at' => now()->addSeconds(self::LEASE_SECONDS)]);
            } elseif (! in_array($step->status, ['completed', 'failed'], true)) {
                return null;
            }

            return $step;
        }, 3);
    }

    public function recordStep(AutomationRun $run, string $token, AutomationRunStep $step, ?array $output, ?string $error): bool
    {
        return DB::transaction(function () use ($run, $token, $step, $output, $error): bool {
            $managed = $this->owned($run->id, $token);
            if (! $managed) {
                return false;
            }
            $current = $managed->steps()->whereKey($step->id)->where('status', 'running')->first();
            if (! $current) {
                return false;
            }
            $current->update([
                'status' => $error === null ? 'completed' : 'failed',
                'output' => $output,
                'error_message' => $error,
                'finished_at' => now(),
            ]);
            $managed->update(['execution_expires_at' => now()->addSeconds(self::LEASE_SECONDS)]);

            return true;
        }, 3);
    }

    public function wait(AutomationRun $run, string $token, AutomationRunStep $step, CarbonInterface $resumeAt): bool
    {
        return DB::transaction(function () use ($run, $token, $step, $resumeAt): bool {
            $managed = $this->owned($run->id, $token);
            if (! $managed) {
                return false;
            }
            $current = $managed->steps()->whereKey($step->id)->where('status', 'running')->first();
            if (! $current) {
                return false;
            }
            $current->update([
                'status' => 'completed', 'finished_at' => now(),
                'output' => ['resume_at' => $resumeAt->toIso8601String()],
            ]);
            $managed->update([
                'status' => AutomationRun::STATUS_WAITING,
                'execution_token' => null, 'execution_expires_at' => null,
            ]);

            return true;
        }, 3);
    }

    public function finish(AutomationRun $run, string $token, bool $withErrors, ?string $error = null): bool
    {
        return DB::transaction(function () use ($run, $token, $withErrors, $error): bool {
            $managed = $this->owned($run->id, $token);
            if (! $managed) {
                return false;
            }
            $failed = $error !== null;
            $managed->update([
                'status' => $failed ? AutomationRun::STATUS_FAILED : ($withErrors
                    ? AutomationRun::STATUS_COMPLETED_WITH_ERRORS : AutomationRun::STATUS_COMPLETED),
                'error_message' => $error,
                'finished_at' => now(), 'execution_expires_at' => null,
            ]);
            $this->event($managed, $failed, $error);

            return true;
        }, 3);
    }

    public function interrupt(int $runId, ?string $token = null, bool $onlyExpired = false): bool
    {
        return DB::transaction(function () use ($runId, $token, $onlyExpired): bool {
            $run = AutomationRun::query()->lockForUpdate()->find($runId);
            if (! $run || $run->status !== AutomationRun::STATUS_RUNNING
                || ($token === null && ! $onlyExpired)
                || ($token !== null && $run->execution_token !== $token)) {
                return false;
            }
            if ($onlyExpired && ($run->execution_expires_at
                ? $run->execution_expires_at->isFuture()
                : $run->updated_at->gt(now()->subSeconds(self::LEASE_SECONDS)))) {
                return false;
            }

            $message = $this->interruptionMessage();
            $run->steps()->where('status', 'running')->update([
                'status' => 'uncertain', 'error_message' => $message, 'finished_at' => now(), 'updated_at' => now(),
            ]);
            $run->update([
                'status' => AutomationRun::STATUS_INTERRUPTED, 'error_message' => $message,
                'finished_at' => now(), 'execution_expires_at' => null,
            ]);
            $this->event($run, true, $message);

            return true;
        }, 3);
    }

    public function recoverExpired(): int
    {
        $recovered = 0;
        AutomationRun::query()->where('status', AutomationRun::STATUS_RUNNING)
            ->where(fn ($query) => $query->where('execution_expires_at', '<=', now())
                ->orWhere(fn ($legacy) => $legacy->whereNull('execution_expires_at')
                    ->where('updated_at', '<=', now()->subSeconds(self::LEASE_SECONDS))))
            ->select('id')->chunkById(100, function ($runs) use (&$recovered): void {
                foreach ($runs as $run) {
                    $recovered += (int) $this->interrupt($run->id, onlyExpired: true);
                }
            });

        return $recovered;
    }

    private function owned(int $runId, string $token): ?AutomationRun
    {
        $run = AutomationRun::query()->lockForUpdate()->find($runId);

        return $run && $run->status === AutomationRun::STATUS_RUNNING
            && $run->execution_token === $token && $run->execution_expires_at?->isFuture() ? $run : null;
    }

    private function event(AutomationRun $run, bool $failed, ?string $error): void
    {
        $run->order->events()->create([
            'event_type' => $failed ? 'automation_failed' : 'automation_completed',
            'title' => html_entity_decode($run->status === AutomationRun::STATUS_INTERRUPTED
                ? 'Przerwano automatyczn&#261; akcj&#281;'
                : ($failed ? 'B&#322;&#261;d automatycznej akcji' : 'Automatyczna akcja wykonana'), ENT_QUOTES, 'UTF-8'),
            'description' => (string) data_get($run->rule_snapshot, 'name', 'Automatyczna akcja')
                .($error === null ? '' : ': '.$error),
            'payload' => [
                'automation_run_id' => $run->id, 'automation_rule_id' => $run->automation_rule_id,
                'event_name' => $run->event_name, 'status' => $run->status,
            ],
        ]);
    }
}
