<?php

namespace Modules\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrderStatusSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Automation\Models\AutomationCustomEvent;
use Modules\Automation\Services\AutomationCustomEventAppearance;
use Modules\Automation\Services\AutomationCustomEventSettings;

class AutomationCustomEventController extends Controller
{
    public function index(): View
    {
        $events = AutomationCustomEvent::query()->orderBy('sort_order')->orderBy('id')->get();

        return view('orders.automation-custom-events', [
            'events' => $events,
            'statuses' => OrderStatusSetting::orderedStatuses(),
            'icons' => AutomationCustomEventAppearance::ICONS,
            'colors' => AutomationCustomEventAppearance::COLORS,
        ]);
    }

    public function store(Request $request, AutomationCustomEventSettings $settings): JsonResponse
    {
        $settings->save(null, $this->eventData($request));

        return response()->json(['saved' => true], 201);
    }

    public function update(Request $request, AutomationCustomEvent $customEvent, AutomationCustomEventSettings $settings): JsonResponse
    {
        $settings->save($customEvent, $this->eventData($request));

        return response()->json(['saved' => true]);
    }

    public function destroy(AutomationCustomEvent $customEvent, AutomationCustomEventSettings $settings): JsonResponse
    {
        $settings->delete($customEvent);

        return response()->json(['saved' => true]);
    }

    public function reorder(Request $request, AutomationCustomEventSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'event_ids' => ['required', 'array', 'list', 'min:2'],
            'event_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'previous_event_ids' => ['required', 'array', 'list', 'min:2'],
            'previous_event_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $settings->reorder(array_map('intval', $data['event_ids']), array_map('intval', $data['previous_event_ids']));

        return response()->json(['saved' => true]);
    }

    public function saveLayout(Request $request, AutomationCustomEventSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'layout' => ['required', 'array', 'list', 'max:'.AutomationCustomEvent::MAX_POSITIONS],
            'layout.*' => ['required', 'array:id,position'],
            'layout.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'layout.*.position' => ['required', 'integer', 'min:1', 'max:'.AutomationCustomEvent::MAX_POSITIONS, 'distinct'],
            'previous_layout' => ['required', 'array', 'list', 'max:'.AutomationCustomEvent::MAX_POSITIONS],
            'previous_layout.*' => ['required', 'array:id,position'],
            'previous_layout.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'previous_layout.*.position' => ['required', 'integer', 'min:1', 'max:'.AutomationCustomEvent::MAX_POSITIONS, 'distinct'],
            'max_columns' => ['required_with:previous_max_columns', 'integer', 'min:1', 'max:'.AutomationCustomEventSettings::MAX_COLUMNS],
            'previous_max_columns' => ['required_with:max_columns', 'integer', 'min:1', 'max:'.AutomationCustomEventSettings::MAX_COLUMNS],
        ]);

        $positions = static fn (array $entries): array => array_map('intval', array_column($entries, 'position', 'id'));
        $settings->saveLayout(
            $positions($data['layout']),
            $positions($data['previous_layout']),
            isset($data['max_columns']) ? (int) $data['max_columns'] : null,
            isset($data['previous_max_columns']) ? (int) $data['previous_max_columns'] : null,
        );

        return response()->json(['saved' => true]);
    }

    private function eventData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'button_enabled' => ['required', 'boolean'],
            'button_label' => ['nullable', 'string', 'max:80'],
            'icon' => ['required', 'string', Rule::in(array_keys(AutomationCustomEventAppearance::ICONS))],
            'color' => ['required', 'string', Rule::in(AutomationCustomEventAppearance::COLORS)],
            'confirmation_required' => ['required', 'boolean'],
            'order_statuses' => ['nullable', 'array', 'list'],
            'order_statuses.*' => ['required', 'string', 'distinct', Rule::in(array_keys(OrderStatusSetting::orderedStatuses()))],
        ]);

        return $data + ['description' => null, 'button_label' => null, 'order_statuses' => null];
    }
}
