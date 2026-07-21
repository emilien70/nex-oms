<?php

namespace Modules\Automation\Services;

use Illuminate\Support\Facades\DB;
use Modules\Automation\Models\AutomationRule;

class AutomationRuleService
{
    public function __construct(private readonly AutomationCatalog $catalog) {}

    public function create(array $data): AutomationRule
    {
        return $this->save(new AutomationRule, $data);
    }

    public function update(AutomationRule $rule, array $data): AutomationRule
    {
        return $this->save($rule, $data);
    }

    private function save(AutomationRule $rule, array $data): AutomationRule
    {
        return DB::transaction(function () use ($rule, $data): AutomationRule {
            $rule->fill([
                'name' => filled($data['name'] ?? null)
                    ? trim($data['name'])
                    : $this->generatedName($data),
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'trigger' => $data['trigger'],
                'conditions' => array_values($data['conditions'] ?? []),
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);

            if (! $rule->exists) {
                $rule->sort_order = ((int) AutomationRule::query()->max('sort_order')) + 1;
            }

            $rule->save();
            $rule->actions()->delete();

            foreach (array_values($data['actions']) as $index => $action) {
                $rule->actions()->create([
                    'action_type' => $action['type'],
                    'configuration' => $action['configuration'] ?? [],
                    'stop_on_error' => (bool) ($action['stop_on_error'] ?? true),
                    'sort_order' => $index + 1,
                ]);
            }

            return $rule->fresh('actions');
        });
    }

    private function generatedName(array $data): string
    {
        $action = $data['actions'][0] ?? [];
        $trigger = $this->catalog->triggerLabel((string) $data['trigger']);
        $summary = $this->catalog->actionSummary(
            (string) ($action['type'] ?? ''),
            $action['configuration'] ?? [],
        );

        return mb_substr($trigger.' - '.$summary, 0, 255);
    }
}
