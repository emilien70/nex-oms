<?php

namespace Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Automation\Http\Requests\SaveAutomationRuleRequest;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRuleGroup;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationRuleService;

class AutomationRuleController extends Controller
{
    public function index(Request $request, AutomationCatalog $catalog): View
    {
        $groups = AutomationRuleGroup::query()->inDisplayOrder()->get()->keyBy('id');
        $requestedGroup = $request->query('group');
        $groupFilter = is_string($requestedGroup) && ($requestedGroup === 'none' || $groups->has($requestedGroup))
            ? $requestedGroup
            : null;
        $hasFilters = $request->filled('search') || $request->filled('trigger') || $request->filled('active') || $groupFilter !== null;
        $grouped = $groups->isNotEmpty() && $request->query('view') !== 'order';

        $rules = AutomationRule::query()
            ->with('actions')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('trigger'), fn ($query) => $query->where('trigger', $request->input('trigger')))
            ->when($request->input('active') !== null && $request->input('active') !== '', function ($query) use ($request): void {
                $query->where('is_active', $request->boolean('active'));
            })
            ->when($groupFilter !== null, fn ($query) => $groupFilter === 'none'
                ? $query->whereNull('group_id')
                : $query->where('group_id', $groupFilter))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $sections = [['id' => null, 'name' => null, 'rules' => $rules]];
        if ($grouped) {
            $byGroup = $rules->groupBy(fn (AutomationRule $rule) => $rule->group_id ?? 'none');
            $labels = collect(['none' => 'Brak grupy'])->union($groups->pluck('name', 'id'));
            $sections = $labels
                ->filter(fn ($name, $id): bool => $groupFilter !== null
                    ? (string) $id === $groupFilter
                    : (! $hasFilters || $byGroup->has($id)))
                ->map(fn ($name, $id): array => ['id' => $id, 'name' => $name, 'rules' => $byGroup->get($id, collect())])
                ->values()->all();
        }

        return view('orders.automatic-actions', [
            'rules' => $rules,
            'groups' => $groups,
            'groupFilter' => $groupFilter,
            'grouped' => $grouped,
            'hasFilters' => $hasFilters,
            'sections' => $sections,
            'canReorder' => ! $grouped && ! $hasFilters && $rules->count() > 1,
            'catalog' => $catalog,
            'statuses' => OrderStatusSetting::orderedStatuses(),
            'shipmentActionDefinitions' => $catalog->shipmentActionDefinitions(),
            'invoiceSeries' => $catalog->invoiceSeries(),
            'emailTemplates' => $catalog->emailTemplates(),
            'smsTemplates' => $catalog->smsTemplates(),
            'nexPrinters' => $catalog->nexPrinters(),
            'printDocumentTypes' => $catalog->printDocumentTypes(),
        ]);
    }

    public function reorder(Request $request, AutomationRuleService $rules): JsonResponse
    {
        $data = $request->validate([
            'rule_ids' => ['required', 'array', 'list', 'min:2'],
            'rule_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'previous_rule_ids' => ['required', 'array', 'list', 'min:2'],
            'previous_rule_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $rules->reorder(array_map('intval', $data['rule_ids']), array_map('intval', $data['previous_rule_ids']));

        return response()->json(['saved' => true]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('orders.automatic-actions.index');
    }

    public function store(
        SaveAutomationRuleRequest $request,
        AutomationRuleService $rules,
    ): JsonResponse|RedirectResponse {
        $rule = $rules->create($request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Automatyczna akcja zostala utworzona.',
                'redirect_url' => route('orders.automatic-actions.index'),
            ], 201);
        }

        return redirect()->route('orders.automatic-actions.index', ['edit' => $rule->id])
            ->with('success', 'Automatyczna akcja zostala utworzona.');
    }

    public function edit(AutomationRule $automationRule): RedirectResponse
    {
        return redirect()->route('orders.automatic-actions.index', ['edit' => $automationRule->id]);
    }

    public function update(
        SaveAutomationRuleRequest $request,
        AutomationRule $automationRule,
        AutomationRuleService $rules,
    ): JsonResponse|RedirectResponse {
        $rules->update($automationRule, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Automatyczna akcja zostala zapisana.',
                'redirect_url' => route('orders.automatic-actions.index'),
            ]);
        }

        return redirect()->route('orders.automatic-actions.index')
            ->with('success', 'Automatyczna akcja zostala zapisana.');
    }

    public function toggle(Request $request, AutomationRule $automationRule): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $automationRule->update(['is_active' => (bool) $data['is_active']]);

        return back();
    }

    public function destroy(AutomationRule $automationRule): RedirectResponse
    {
        $automationRule->delete();

        return redirect()->route('orders.automatic-actions.index');
    }
}
