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
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationRuleService;

class AutomationRuleController extends Controller
{
    public function index(Request $request, AutomationCatalog $catalog): View
    {
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
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('orders.automatic-actions', [
            'rules' => $rules,
            'catalog' => $catalog,
            'statuses' => OrderStatusSetting::orderedStatuses(),
            'shipmentActionDefinitions' => $catalog->shipmentActionDefinitions(),
            'invoiceSeries' => $catalog->invoiceSeries(),
        ]);
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
