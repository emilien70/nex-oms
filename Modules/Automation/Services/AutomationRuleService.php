<?php

namespace Modules\Automation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Automation\Models\AutomationCustomEvent;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRuleGroup;

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

    public function reorder(array $ids, array $previousIds): void
    {
        if (count($ids) !== count($previousIds) || array_diff($ids, $previousIds) !== []) {
            throw ValidationException::withMessages(['rule_ids' => 'Przekazana lista regul jest niekompletna lub nieprawidlowa.']);
        }

        DB::transaction(function () use ($ids, $previousIds): void {
            $rules = AutomationRule::query()->orderBy('id')->lockForUpdate()->get(['id', 'sort_order']);
            $currentIds = $rules->sortBy([['sort_order', 'asc'], ['id', 'asc']])->pluck('id')->all();

            abort_if($currentIds !== $previousIds, 409, 'Lista regul zmienila sie. Odswiez strone i sprobuj ponownie.');

            $byId = $rules->keyBy('id');
            foreach ($ids as $index => $id) {
                $rule = $byId->get($id);
                if ($rule->sort_order !== $index + 1) {
                    $rule->update(['sort_order' => $index + 1]);
                }
            }
        }, attempts: 3);
    }

    private function save(AutomationRule $rule, array $data): AutomationRule
    {
        return DB::transaction(function () use ($rule, $data): AutomationRule {
            if (str_starts_with($data['trigger'], AutomationCustomEvent::TRIGGER_PREFIX)
                && ! AutomationCustomEvent::query()->lockForUpdate()
                    ->find(substr($data['trigger'], strlen(AutomationCustomEvent::TRIGGER_PREFIX)), ['id'])) {
                throw ValidationException::withMessages(['trigger' => 'Wybrane zdarzenie już nie istnieje.']);
            }

            if (array_key_exists('group_id', $data)) {
                if ($data['group_id'] !== null && ! AutomationRuleGroup::query()->lockForUpdate()->find($data['group_id'], ['id'])) {
                    throw ValidationException::withMessages(['group_id' => 'Wybrana grupa już nie istnieje.']);
                }
                $rule->group_id = $data['group_id'];
            }

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
