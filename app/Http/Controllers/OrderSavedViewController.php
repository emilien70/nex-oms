<?php

namespace App\Http\Controllers;

use App\Models\OrderSavedView;
use App\Models\OrderStatusSetting;
use App\Support\OrderListFilterState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderSavedViewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $savedView = DB::transaction(function () use ($validated): OrderSavedView {
            return OrderSavedView::query()->create([
                'name' => $validated['name'],
                'filters' => OrderListFilterState::normalizeSaved($validated['filters'] ?? []),
                'sort_order' => ((int) OrderSavedView::query()->max('sort_order')) + 1,
            ]);
        });

        return response()->json([
            'saved' => true,
            'view' => $this->payload($savedView),
        ], 201);
    }

    public function update(Request $request, OrderSavedView $orderSavedView): JsonResponse
    {
        $validated = $request->validate($this->rules($orderSavedView));

        if (! array_key_exists('name', $validated) && ! $request->boolean('replace_filters')) {
            throw ValidationException::withMessages([
                'view' => 'Nie przekazano zmian do zapisania.',
            ]);
        }

        if (array_key_exists('name', $validated)) {
            $orderSavedView->name = $validated['name'];
        }

        if ($request->boolean('replace_filters')) {
            $orderSavedView->filters = OrderListFilterState::normalizeSaved($validated['filters'] ?? []);
        }

        $orderSavedView->save();

        return response()->json([
            'saved' => true,
            'view' => $this->payload($orderSavedView->fresh()),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'views' => ['required', 'array'],
            'views.*' => ['required', 'integer', 'distinct', Rule::exists('order_saved_views', 'id')],
        ]);

        $ids = array_map('intval', array_values($validated['views']));
        $storedIds = OrderSavedView::query()->ordered()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $submitted = $ids;
        sort($submitted);
        $stored = $storedIds;
        sort($stored);

        if ($submitted !== $stored) {
            throw ValidationException::withMessages([
                'views' => 'Lista zapisanych widokow jest niekompletna.',
            ]);
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                OrderSavedView::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json(['saved' => true]);
    }

    public function destroy(OrderSavedView $orderSavedView): JsonResponse
    {
        DB::transaction(function () use ($orderSavedView): void {
            $orderSavedView->delete();

            OrderSavedView::query()
                ->ordered()
                ->get()
                ->each(fn (OrderSavedView $view, int $index) => $view->update(['sort_order' => $index + 1]));
        });

        return response()->json(['deleted' => true]);
    }

    private function rules(?OrderSavedView $savedView = null): array
    {
        $nameRules = [
            $savedView ? 'sometimes' : 'required',
            'string',
            'max:100',
            Rule::unique('order_saved_views', 'name')->ignore($savedView),
        ];
        $allowedKeys = implode(',', OrderListFilterState::SAVED_KEYS);
        $rules = [
            'name' => $nameRules,
            'replace_filters' => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'array:'.$allowedKeys],
        ];

        foreach (OrderListFilterState::SAVED_KEYS as $key) {
            $rules['filters.'.$key] = ['sometimes', 'nullable', 'string', 'max:500'];
        }

        $rules['filters.status'][] = Rule::in(array_keys(OrderStatusSetting::orderedStatuses()));
        $rules['filters.source'][] = Rule::in(['allegro', 'prestashop']);
        $rules['filters.cash_on_delivery'][] = Rule::in(['0', '1']);
        $rules['filters.payment'][] = Rule::in(['unpaid', 'partial', 'paid']);

        foreach (['ordered_from', 'ordered_to', 'status_from', 'status_to'] as $key) {
            $rules['filters.'.$key][] = 'date_format:Y-m-d';
        }

        foreach (['total_from', 'total_to', 'delivery_cost_from', 'delivery_cost_to'] as $key) {
            $rules['filters.'.$key][] = 'numeric';
            $rules['filters.'.$key][] = 'min:0';
        }

        return $rules;
    }

    private function payload(OrderSavedView $savedView): array
    {
        return [
            'id' => $savedView->id,
            'name' => $savedView->name,
            'filters' => $savedView->filters,
            'sort_order' => $savedView->sort_order,
            'apply_url' => route('orders.index', $savedView->filters ?? []),
        ];
    }
}
