<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsOrderStatusesController extends Controller
{
    public function index(): View
    {
        $orderCounts = Order::withTrashed()
            ->selectRaw('status, COUNT(*) as orders_count')
            ->groupBy('status')
            ->pluck('orders_count', 'status');
        $statuses = OrderStatusSetting::orderedSettings()
            ->map(function (array $status) use ($orderCounts): array {
                $status['orders_count'] = (int) ($orderCounts[$status['code']] ?? 0);

                return $status;
            });

        return view('settings.order-statuses.index', [
            'statuses' => $statuses,
            'statusOptions' => OrderStatusSetting::orderedStatuses(),
        ]);
    }

    public function update(Request $request, OrderStatusSetting $orderStatusSetting): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $orderStatusSetting->update([
            'short_name' => $validated['name'],
            'full_name' => $validated['description'] ?? null,
            'color' => strtolower($validated['color']),
        ]);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        OrderStatusSetting::syncDefaults();

        $nextSortOrder = ((int) OrderStatusSetting::query()->max('sort_order')) + 1;

        OrderStatusSetting::query()->create([
            'status' => $this->uniqueStatusCode($validated['name']),
            'sort_order' => $nextSortOrder,
            'color' => strtolower($validated['color']),
            'short_name' => $validated['name'],
            'full_name' => $validated['description'],
        ]);

        return back();
    }

    public function updateOrder(Request $request): RedirectResponse
    {
        OrderStatusSetting::syncDefaults();

        $allowedStatuses = array_keys(OrderStatusSetting::orderedStatuses());

        $validated = $request->validate([
            'statuses' => ['required', 'array'],
            'statuses.*' => ['required', 'string', Rule::in($allowedStatuses)],
        ]);

        $statuses = array_values(array_unique($validated['statuses']));

        if (count($statuses) !== count($allowedStatuses)) {
            return back()->withErrors([
                'statuses' => html_entity_decode('Lista status&oacute;w jest niekompletna.', ENT_QUOTES, 'UTF-8'),
            ]);
        }

        foreach ($statuses as $position => $status) {
            OrderStatusSetting::query()
                ->where('status', $status)
                ->update(['sort_order' => $position + 1]);
        }

        return back();
    }

    public function destroy(Request $request, OrderStatusSetting $orderStatusSetting): RedirectResponse
    {
        $availableStatuses = array_keys(OrderStatusSetting::orderedStatuses());
        $replacementStatuses = array_values(array_filter(
            $availableStatuses,
            fn (string $status): bool => $status !== $orderStatusSetting->status
        ));

        if (empty($replacementStatuses)) {
            return back()->withErrors([
                'replacement_status' => html_entity_decode('Nie mo&#380;na usun&#261;&#263; ostatniego aktywnego statusu.', ENT_QUOTES, 'UTF-8'),
            ]);
        }

        $ordersCount = Order::withTrashed()
            ->where('status', $orderStatusSetting->status)
            ->count();

        if ($ordersCount > 0) {
            $validated = $request->validate([
                'replacement_status' => ['required', 'string', Rule::in($replacementStatuses)],
            ]);
        } else {
            $validated = [
                'replacement_status' => null,
            ];
        }

        DB::transaction(function () use ($orderStatusSetting, $ordersCount, $validated): void {
            if ($ordersCount > 0) {
                $changedAt = now();

                Order::withTrashed()
                    ->where('status', $orderStatusSetting->status)
                    ->update([
                        'status' => $validated['replacement_status'],
                        'status_changed_at' => $changedAt,
                    ]);
            }

            $orderStatusSetting->delete();

            OrderStatusSetting::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->each(fn (OrderStatusSetting $setting, int $index) => $setting->update(['sort_order' => $index + 1]));
        });

        return back();
    }

    private function uniqueStatusCode(string $name): string
    {
        $baseCode = Str::slug($name);

        if ($baseCode === '') {
            $baseCode = 'status';
        }

        $code = $baseCode;
        $suffix = 2;

        while (OrderStatusSetting::withTrashed()->where('status', $code)->exists()) {
            $code = $baseCode . '-' . $suffix;
            $suffix++;
        }

        return $code;
    }
}
