<?php

namespace Modules\Automation\Services;

use App\Models\Order;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;

class AutomationEngine
{
    private const MAX_CHAIN_DEPTH = 10;

    public function __construct(private readonly AutomationConditionEvaluator $evaluator) {}

    public function evaluate(string $eventName, array $eventPayload): void
    {
        $order = Order::query()->find($eventPayload['order_id'] ?? null);

        if (! $order) {
            return;
        }

        [$chainId, $depth] = $this->chainContext($eventPayload);
        $eventId = (string) ($eventPayload['event_id'] ?? Str::uuid());

        if ($depth > self::MAX_CHAIN_DEPTH) {
            return;
        }

        $rules = AutomationRule::query()
            ->with('actions')
            ->where('is_active', true)
            ->where('trigger', $eventName)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (AutomationRule $rule): bool => $this->evaluator->matches(
                $order,
                $eventPayload,
                $rule->conditions ?? [],
            ));

        foreach ($rules as $rule) {
            if (AutomationRun::query()->where('chain_id', $chainId)->where('automation_rule_id', $rule->id)->exists()) {
                continue;
            }

            $run = AutomationRun::query()->firstOrCreate(
                [
                    'automation_rule_id' => $rule->id,
                    'event_id' => $eventId,
                ],
                [
                    'order_id' => $order->id,
                    'event_name' => $eventName,
                    'chain_id' => $chainId,
                    'depth' => $depth,
                    'status' => AutomationRun::STATUS_QUEUED,
                    'event_payload' => $eventPayload,
                    'rule_snapshot' => [
                        'name' => $rule->name,
                        'description' => $rule->description,
                        'trigger' => $rule->trigger,
                        'conditions' => $rule->conditions ?? [],
                        'actions' => $rule->actions->map(fn ($action): array => [
                            'type' => $action->action_type,
                            'configuration' => $action->configuration ?? [],
                            'stop_on_error' => $action->stop_on_error,
                        ])->values()->all(),
                    ],
                ],
            );

            if ($run->wasRecentlyCreated) {
                ExecuteAutomationRunJob::dispatch($run, 0);
            }
        }
    }

    private function chainContext(array $eventPayload): array
    {
        $source = (string) ($eventPayload['source'] ?? '');

        if (preg_match('/^automation_run:(\d+)$/', $source, $matches)) {
            $parentRun = AutomationRun::query()->find((int) $matches[1]);

            if ($parentRun) {
                return [$parentRun->chain_id, $parentRun->depth + 1];
            }
        }

        return [(string) Str::uuid(), 0];
    }
}
