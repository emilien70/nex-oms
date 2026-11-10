<?php

namespace Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Automation\Models\AutomationRuleGroup;

class AutomationRuleGroupController extends Controller
{
    public function index(): View
    {
        return view('orders.automation-rule-groups', [
            'groups' => AutomationRuleGroup::query()->inDisplayOrder()->withCount('rules')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $name = $this->validateName($request);
        $this->saveGroup(function () use ($name): void {
            DB::transaction(function () use ($name): void {
                $max = AutomationRuleGroup::query()->orderBy('id')->lockForUpdate()->get(['sort_order'])->max('sort_order');
                AutomationRuleGroup::create(['name' => $name, 'sort_order' => (int) $max + 1]);
            }, attempts: 3);
        });

        return response()->json(['saved' => true], 201);
    }

    public function update(Request $request, AutomationRuleGroup $automationRuleGroup): JsonResponse
    {
        $name = $this->validateName($request, $automationRuleGroup);
        $this->saveGroup(fn () => $automationRuleGroup->update(['name' => $name]));

        return response()->json(['saved' => true]);
    }

    public function destroy(AutomationRuleGroup $automationRuleGroup): JsonResponse
    {
        $automationRuleGroup->delete();

        return response()->json(['saved' => true]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_ids' => ['required', 'array', 'list', 'min:2'],
            'group_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'previous_group_ids' => ['required', 'array', 'list', 'min:2'],
            'previous_group_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $data['group_ids']);
        $previous = array_map('intval', $data['previous_group_ids']);
        if (count($ids) !== count($previous) || array_diff($ids, $previous) !== []) {
            throw ValidationException::withMessages(['group_ids' => 'Lista grup jest niekompletna lub nieprawidłowa.']);
        }

        DB::transaction(function () use ($ids, $previous): void {
            $groups = AutomationRuleGroup::query()->orderBy('id')->lockForUpdate()->get(['id', 'sort_order']);
            $current = $groups->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()->modelKeys();
            abort_if($current !== $previous, 409, 'Lista grup zmieniła się. Odśwież stronę i spróbuj ponownie.');

            $byId = $groups->keyBy('id');
            foreach ($ids as $index => $id) {
                $group = $byId->get($id);
                if ($group->sort_order !== $index + 1) {
                    $group->update(['sort_order' => $index + 1]);
                }
            }
        }, attempts: 3);

        return response()->json(['saved' => true]);
    }

    private function validateName(Request $request, ?AutomationRuleGroup $group = null): string
    {
        $unique = Rule::unique('automation_rule_groups', 'name');
        if ($group !== null) {
            $unique->ignore($group);
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:50', $unique]]);
        if (mb_strtolower($data['name']) === 'brak grupy') {
            throw ValidationException::withMessages(['name' => 'Nazwa „Brak grupy” jest zarezerwowana.']);
        }

        return $data['name'];
    }

    private function saveGroup(callable $save): void
    {
        try {
            $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Grupa o tej nazwie już istnieje.']);
        }
    }
}
