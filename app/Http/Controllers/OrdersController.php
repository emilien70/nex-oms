<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToOrderAjax;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use App\Services\OrderTrackingLookupService;
use App\Services\OrderTrashService;
use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class OrdersController extends Controller
{
    use RespondsToOrderAjax;

    public function __construct(private readonly OrderTrackingLookupService $trackingLookup) {}

    public function index(Request $request): View|RedirectResponse
    {
        $currentStatus = $request->query('status');
        $currentStatus = is_string($currentStatus) ? $currentStatus : null;
        $showTrash = $request->boolean('trash');
        $searchQuery = trim((string) $request->query('q', ''));

        if ($searchQuery !== '') {
            $currentStatus = null;
            $showTrash = false;
        }

        $filters = $this->orderFilters($request);
        $perPageOptions = [20, 30, 50, 75, 100, 150, 200, 300, 500, 1000];
        $requestedPerPage = $request->integer('per_page', 20);
        $perPage = in_array($requestedPerPage, $perPageOptions, true) ? $requestedPerPage : 20;
        $statusSettings = OrderStatusSetting::orderedSettings()->keyBy('code');
        $statuses = $statusSettings
            ->mapWithKeys(fn (array $status): array => [$status['code'] => $status['name']])
            ->all();

        if (! $showTrash && $searchQuery !== '') {
            $trackingOrderIds = $this->trackingLookup->matchingOrderIds(
                $this->ordersListQuery(
                    false,
                    $currentStatus,
                    $statuses,
                    $searchQuery,
                    $filters
                ),
                $searchQuery
            );

            if ($trackingOrderIds->count() === 1) {
                return redirect()->route('orders.show', $trackingOrderIds->first());
            }
        }

        $statusCounts = $this->orderStatusCounts($statuses);
        $trashCount = Order::onlyTrashed()->count();
        $ordersQuery = $this->ordersListQuery(
            $showTrash,
            $currentStatus,
            $statuses,
            $searchQuery,
            $filters,
            true
        );
        $listSignature = $this->ordersListSignature($ordersQuery, $statusCounts, $trashCount);

        $allMatchingOrderIds = (clone $ordersQuery)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $starredMatchingOrderIds = (clone $ordersQuery)
            ->whereNotNull('star_color')
            ->where('star_color', '<>', '')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $orders = $ordersQuery
            ->paginate($perPage)
            ->appends(array_merge($request->except(['page', 'per_page']), ['per_page' => $perPage]));

        return view('orders.index', [
            'orders' => $orders,
            'statuses' => $statuses,
            'statusSettings' => $statusSettings,
            'statusCounts' => $statusCounts,
            'currentStatus' => ! $showTrash && array_key_exists((string) $currentStatus, $statuses) ? $currentStatus : null,
            'showTrash' => $showTrash,
            'searchQuery' => $searchQuery,
            'filters' => $filters,
            'hasActiveFilters' => $this->hasActiveFilters($filters),
            'sourceOptions' => $this->sourceOptions(),
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
            'allMatchingOrderIds' => $allMatchingOrderIds,
            'starredMatchingOrderIds' => $starredMatchingOrderIds,
            'listSignature' => $listSignature,
        ]);
    }

    public function listState(Request $request): JsonResponse
    {
        $currentStatus = $request->query('status');
        $currentStatus = is_string($currentStatus) ? $currentStatus : null;
        $showTrash = $request->boolean('trash');
        $searchQuery = trim((string) $request->query('q', ''));
        $filters = $this->orderFilters($request);
        $statuses = OrderStatusSetting::orderedStatuses();
        $statusCounts = $this->orderStatusCounts($statuses);
        $trashCount = Order::onlyTrashed()->count();
        $ordersQuery = $this->ordersListQuery(
            $showTrash,
            $currentStatus,
            $statuses,
            $searchQuery,
            $filters
        );

        return response()->json([
            'signature' => $this->ordersListSignature($ordersQuery, $statusCounts, $trashCount),
            'status_counts' => $statusCounts,
            'trash_count' => $trashCount,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function create(): View
    {
        return view('orders.create', [
            'order' => new Order([
                'source' => 'manual',
                'status' => Order::STATUS_NEW,
                'currency' => 'PLN',
                'payment_status' => 'unpaid',
            ]),
            'statuses' => OrderStatusSetting::orderedStatuses(),
            'sourceOptions' => $this->sourceOptions(),
            'paymentStatusOptions' => $this->paymentStatusOptions(),
            'itemRows' => $this->emptyItemRows(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateOrder($request);

        $order = DB::transaction(function () use ($validated): Order {
            $order = Order::create($this->orderData($validated)
                + $this->orderAddressData($validated, 'shipping')
                + $this->orderAddressData($validated, 'billing')
                + [
                    'status_changed_at' => now(),
                ]);

            $this->syncItems($order, $validated['items'] ?? []);

            $order->events()->create([
                'event_type' => 'order_created',
                'title' => html_entity_decode('Zam&oacute;wienie utworzone', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Utworzono zam&oacute;wienie r&#281;cznie', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'source' => $order->source,
                    'order_id' => $order->id,
                ],
            ]);

            return $order;
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', html_entity_decode('Zam&oacute;wienie zosta&#322;o utworzone.', ENT_QUOTES, 'UTF-8'));
    }

    public function storeEmpty(): RedirectResponse
    {
        $order = DB::transaction(function (): Order {
            $order = Order::create([
                'source' => 'manual',
                'status' => Order::STATUS_NEW,
                'status_changed_at' => now(),
                'currency' => 'PLN',
                'total_gross' => 0,
                'paid_amount' => 0,
                'delivery_cost_gross' => 0,
                'cash_on_delivery' => false,
                'payment_status' => 'unpaid',
            ]);

            $order->events()->create([
                'event_type' => 'order_created',
                'title' => html_entity_decode('Zam&oacute;wienie utworzone', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Utworzono puste zam&oacute;wienie r&#281;czne', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'source' => $order->source,
                    'order_id' => $order->id,
                ],
            ]);

            return $order;
        });

        return redirect()->route('orders.show', $order);
    }

    public function bulkTrash(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $this->validateSelectedOrders($request);

        Order::query()
            ->whereIn('id', $validated['order_ids'])
            ->delete();

        return $this->orderMutationResponse($request, ['list', 'counts'], back());
    }

    public function bulkRestore(Request $request, OrderTrashService $orderTrashService): JsonResponse|RedirectResponse
    {
        $validated = $this->validateSelectedOrders($request);

        $orderTrashService->restoreMany($validated['order_ids']);

        return $this->orderMutationResponse($request, ['list', 'counts'], back());
    }

    public function bulkForceDelete(Request $request, OrderTrashService $orderTrashService): JsonResponse|RedirectResponse
    {
        $validated = $this->validateSelectedOrders($request);

        $orderTrashService->forceDeleteMany($validated['order_ids']);

        return $this->orderMutationResponse($request, ['list', 'counts'], back());
    }

    public function bulkUpdateStatus(Request $request, OrderStatusService $orderStatusService): JsonResponse|RedirectResponse
    {
        $statuses = OrderStatusSetting::orderedStatuses();

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys($statuses))],
        ]);

        DB::transaction(function () use ($validated, $orderStatusService): void {
            Order::query()
                ->whereIn('id', $validated['order_ids'])
                ->get()
                ->each(fn (Order $order) => $orderStatusService->change($order, $validated['status'], 'bulk'));
        });

        return $this->orderMutationResponse($request, ['list', 'counts'], back());
    }

    public function destroy(Order $order): RedirectResponse
    {
        $order->delete();

        return redirect()->route('orders.index');
    }

    public function createForCustomer(Order $order): RedirectResponse
    {
        $newOrder = DB::transaction(function () use ($order): Order {
            $newOrder = Order::create([
                'source' => 'manual',
                'status' => Order::STATUS_NEW,
                'status_changed_at' => now(),
                'customer_login' => $order->customer_login,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'currency' => $order->currency ?: 'PLN',
                'total_gross' => 0,
                'paid_amount' => 0,
                'delivery_cost_gross' => 0,
                'cash_on_delivery' => false,
                'payment_status' => 'unpaid',
            ] + $this->copyOrderAddressData($order));

            $newOrder->events()->create([
                'event_type' => 'order_created_for_customer',
                'title' => html_entity_decode('Zam&oacute;wienie utworzone dla klienta', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Utworzono nowe zam&oacute;wienie na podstawie danych klienta', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'source_order_id' => $order->id,
                ],
            ]);

            return $newOrder;
        });

        return redirect()->route('orders.show', $newOrder);
    }

    public function duplicate(Order $order): RedirectResponse
    {
        $order->load(['items']);

        $newOrder = DB::transaction(function () use ($order): Order {
            $orderData = $order->only([
                'source',
                'status',
                'star_color',
                'customer_login',
                'customer_email',
                'customer_phone',
                ...$this->orderAddressFieldNames(),
                'currency',
                'total_gross',
                'paid_amount',
                'delivery_cost_gross',
                'shipping_method',
                'pickup_point_name',
                'pickup_point_id',
                'pickup_point_address',
                'pickup_point_postal_code',
                'pickup_point_city',
                'cash_on_delivery',
                'payment_status',
                'payment_method',
                'purchased_at',
                'paid_at',
                'notes',
            ]);

            $newOrder = Order::create($orderData + [
                'external_id' => null,
                'status_changed_at' => now(),
            ]);

            foreach ($order->items as $item) {
                $newOrder->items()->create($item->only([
                    'external_id',
                    'product_name',
                    'sku',
                    'ean',
                    'offer_id',
                    'quantity',
                    'unit_price_gross',
                    'total_price_gross',
                    'currency',
                    'vat_rate',
                    'weight',
                ]));
            }

            $newOrder->events()->create([
                'event_type' => 'order_duplicated',
                'title' => html_entity_decode('Zam&oacute;wienie skopiowane', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Utworzono kopi&#281; zam&oacute;wienia', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'source_order_id' => $order->id,
                ],
            ]);

            return $newOrder;
        });

        return redirect()->route('orders.show', $newOrder);
    }

    private function orderStatusCounts(array $statuses): array
    {
        $statusCounts = array_fill_keys(array_keys($statuses), 0);

        Order::query()
            ->whereIn('status', array_keys($statuses))
            ->selectRaw('status, COUNT(*) as orders_count')
            ->groupBy('status')
            ->pluck('orders_count', 'status')
            ->each(function ($count, $status) use (&$statusCounts): void {
                $statusCounts[$status] = (int) $count;
            });

        return $statusCounts;
    }

    private function ordersListQuery(
        bool $showTrash,
        ?string $currentStatus,
        array $statuses,
        string $searchQuery,
        array $filters,
        bool $withItems = false
    ): Builder {
        return Order::query()
            ->when($withItems, fn (Builder $query) => $query
                ->with('items')
                ->withExists(['visibleShipments as shipments_exists']))
            ->when($showTrash, fn (Builder $query) => $query->onlyTrashed())
            ->when(
                ! $showTrash && array_key_exists((string) $currentStatus, $statuses),
                fn (Builder $query) => $query->where('status', $currentStatus)
            )
            ->when($searchQuery !== '', function (Builder $query) use ($searchQuery): void {
                $like = $this->likeFilter($searchQuery);

                $query->where(function (Builder $query) use ($searchQuery, $like): void {
                    if (ctype_digit($searchQuery)) {
                        $query->orWhere('id', (int) $searchQuery);
                    }

                    $query
                        ->orWhere('external_id', 'like', $like)
                        ->orWhere('notes', 'like', $like)
                        ->orWhere('customer_login', 'like', $like)
                        ->orWhere('customer_email', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('shipping_name', 'like', $like)
                        ->orWhere('shipping_company_name', 'like', $like)
                        ->orWhereHas('shipments', function (Builder $shipmentQuery) use ($like): void {
                            $this->trackingLookup->constrainShipmentQuery($shipmentQuery, $like);
                        });

                    $parts = preg_split('/\s+/', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);

                    if (count($parts ?: []) >= 2) {
                        $first = $this->likeFilter($parts[0]);
                        $last = $this->likeFilter($parts[count($parts) - 1]);

                        $query->orWhere(function (Builder $nameQuery) use ($first, $last): void {
                            $nameQuery
                                ->where('shipping_name', 'like', $first)
                                ->where('shipping_name', 'like', $last);
                        });
                    }
                });
            })
            ->when($this->hasActiveFilters($filters), fn (Builder $query) => $this->applyOrderFilters($query, $filters))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function ordersListSignature(Builder $query, array $statusCounts, int $trashCount): string
    {
        $matchingOrders = (clone $query)->reorder();
        $matchingOrderIds = (clone $matchingOrders)->select('orders.id');
        $matchingShipments = Shipment::query()
            ->whereNotNull('tracking_number')
            ->whereIn('order_id', $matchingOrderIds);

        return hash('sha256', json_encode([
            'matching_count' => (clone $matchingOrders)->count(),
            'matching_last_update' => (clone $matchingOrders)->max('updated_at'),
            'matching_shipments_count' => (clone $matchingShipments)->count(),
            'matching_shipments_last_update' => (clone $matchingShipments)->max('updated_at'),
            'status_counts' => $statusCounts,
            'trash_count' => $trashCount,
        ], JSON_THROW_ON_ERROR));
    }

    private function orderFilters(Request $request): array
    {
        $keys = [
            'number',
            'store_number',
            'ordered_from',
            'ordered_to',
            'status_from',
            'status_to',
            'source',
            'customer',
            'login',
            'email',
            'phone',
            'city',
            'postal_code',
            'shipping_method',
            'tracking_number',
            'cash_on_delivery',
            'payment',
            'payment_method',
            'total_from',
            'total_to',
            'delivery_cost_from',
            'delivery_cost_to',
            'product',
            'notes',
        ];

        return collect($request->only($keys))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->all();
    }

    private function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function applyOrderFilters($query, array $filters): void
    {
        if ($this->filledFilter($filters, 'number')) {
            $number = $filters['number'];
            $query->whereKey(ctype_digit((string) $number) ? (int) $number : 0);
        }

        if ($this->filledFilter($filters, 'store_number')) {
            $query->where('external_id', 'like', $this->likeFilter($filters['store_number']));
        }

        if ($this->filledFilter($filters, 'ordered_from')) {
            $query->whereRaw('date(COALESCE(purchased_at, created_at)) >= ?', [$filters['ordered_from']]);
        }

        if ($this->filledFilter($filters, 'ordered_to')) {
            $query->whereRaw('date(COALESCE(purchased_at, created_at)) <= ?', [$filters['ordered_to']]);
        }

        if ($this->filledFilter($filters, 'status_from')) {
            $query->whereDate('status_changed_at', '>=', $filters['status_from']);
        }

        if ($this->filledFilter($filters, 'status_to')) {
            $query->whereDate('status_changed_at', '<=', $filters['status_to']);
        }

        if (in_array($filters['source'] ?? null, ['allegro', 'prestashop'], true)) {
            $query->where('source', $filters['source']);
        }

        $this->applyOrderFilter($query, $filters, 'customer', ['shipping_name', 'shipping_company_name']);
        $this->applyOrderFilter($query, $filters, 'login', ['customer_login']);
        $this->applyOrderFilter($query, $filters, 'email', ['customer_email']);
        $this->applyOrderFilter($query, $filters, 'phone', ['customer_phone']);

        $this->applyOrderFilter($query, $filters, 'city', ['shipping_city']);
        $this->applyOrderFilter($query, $filters, 'postal_code', ['shipping_postal_code']);

        if ($this->filledFilter($filters, 'shipping_method')) {
            $query->where('shipping_method', 'like', $this->likeFilter($filters['shipping_method']));
        }

        if ($this->filledFilter($filters, 'tracking_number')) {
            $trackingNumber = $this->likeFilter($filters['tracking_number']);

            $query->whereHas('shipments', function ($shipmentQuery) use ($trackingNumber): void {
                $this->trackingLookup->constrainShipmentQuery($shipmentQuery, $trackingNumber);
            });
        }

        if ($this->filledFilter($filters, 'cash_on_delivery')) {
            $query->where('cash_on_delivery', (bool) $filters['cash_on_delivery']);
        }

        if ($this->filledFilter($filters, 'payment')) {
            match ($filters['payment']) {
                'unpaid' => $query->where('paid_amount', '<=', 0),
                'partial' => $query->where('paid_amount', '>', 0)->whereColumn('paid_amount', '<', 'total_gross'),
                'paid' => $query->where('total_gross', '>', 0)->whereColumn('paid_amount', '>=', 'total_gross'),
                default => null,
            };
        }

        if ($this->filledFilter($filters, 'payment_method')) {
            $query->where('payment_method', 'like', $this->likeFilter($filters['payment_method']));
        }

        if ($this->filledFilter($filters, 'total_from')) {
            $query->where('total_gross', '>=', $filters['total_from']);
        }

        if ($this->filledFilter($filters, 'total_to')) {
            $query->where('total_gross', '<=', $filters['total_to']);
        }

        if ($this->filledFilter($filters, 'delivery_cost_from')) {
            $query->where('delivery_cost_gross', '>=', $filters['delivery_cost_from']);
        }

        if ($this->filledFilter($filters, 'delivery_cost_to')) {
            $query->where('delivery_cost_gross', '<=', $filters['delivery_cost_to']);
        }

        if ($this->filledFilter($filters, 'product')) {
            $query->whereHas('items', function ($itemQuery) use ($filters): void {
                $itemQuery->where('product_name', 'like', $this->likeFilter($filters['product']));
            });
        }

        if ($this->filledFilter($filters, 'notes')) {
            $query->where('notes', 'like', $this->likeFilter($filters['notes']));
        }
    }

    private function applyOrderFilter($query, array $filters, string $key, array $columns): void
    {
        if (! $this->filledFilter($filters, $key)) {
            return;
        }

        $query->where(function ($query) use ($filters, $key, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', $this->likeFilter($filters[$key]));
            }
        });
    }

    private function filledFilter(array $filters, string $key): bool
    {
        return isset($filters[$key]) && $filters[$key] !== '';
    }

    private function likeFilter(string $value): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $value).'%';
    }

    public function show(Order $order): View
    {
        $activeCourierAccounts = CourierAccount::query()
            ->where('is_active', true)
            ->get();

        $order->load([
            'items',
            'shipments' => fn ($query) => $query
                ->whereNotNull('tracking_number')
                ->orderByDesc('created_at'),
            'shipments.courierAccount',
            'shipments.parcels',
            'events' => fn ($query) => $query->orderByDesc('created_at'),
        ]);

        return view('orders.show', [
            'order' => $order,
            'statuses' => OrderStatusSetting::orderedStatuses(),
            'statusSettings' => OrderStatusSetting::orderedSettings()->keyBy('code'),
            'activeCourierAccounts' => $activeCourierAccounts,
            'quickStatusActions' => [
                Order::STATUS_PENDING => [
                    'label' => html_entity_decode('Oznacz jako oczekuj&#261;ce', ENT_QUOTES, 'UTF-8'),
                    'status' => Order::STATUS_PENDING,
                ],
                Order::STATUS_SHIPPED => [
                    'label' => html_entity_decode('Oznacz jako wys&#322;ane', ENT_QUOTES, 'UTF-8'),
                    'status' => Order::STATUS_SHIPPED,
                ],
                Order::STATUS_CANCELLED => [
                    'label' => html_entity_decode('Anuluj zam&oacute;wienie', ENT_QUOTES, 'UTF-8'),
                    'status' => Order::STATUS_CANCELLED,
                ],
                Order::STATUS_NEW => [
                    'label' => html_entity_decode('Przywr&oacute;&#263; jako nowe', ENT_QUOTES, 'UTF-8'),
                    'status' => Order::STATUS_NEW,
                ],
            ],
            'sourceOptions' => $this->sourceOptions(),
            'paymentStatusOptions' => $this->paymentStatusOptions(),
            'itemRows' => $this->itemRows($order),
        ]);
    }

    public function edit(Order $order): View
    {
        $order->load(['items']);

        return view('orders.edit', [
            'order' => $order,
            'statuses' => OrderStatusSetting::orderedStatuses(),
            'sourceOptions' => $this->sourceOptions(),
            'paymentStatusOptions' => $this->paymentStatusOptions(),
            'itemRows' => $this->itemRows($order),
        ]);
    }

    public function update(Request $request, Order $order, OrderStatusService $orderStatusService): RedirectResponse
    {
        $validated = $this->validateOrder($request);

        DB::transaction(function () use ($order, $validated, $orderStatusService): void {
            $orderData = $this->orderData($validated)
                + $this->orderAddressData($validated, 'shipping')
                + $this->orderAddressData($validated, 'billing');
            $newStatus = $orderData['status'] ?? $order->status;

            unset($orderData['status'], $orderData['status_changed_at']);

            $order->update($orderData);
            $orderStatusService->change($order, $newStatus, 'order_edit');

            $order->items()->delete();
            $this->syncItems($order, $validated['items'] ?? []);

            $order->events()->create([
                'event_type' => 'order_updated',
                'title' => html_entity_decode('Zam&oacute;wienie zaktualizowane', ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode('Zaktualizowano dane zam&oacute;wienia', ENT_QUOTES, 'UTF-8'),
                'payload' => [
                    'source' => $order->source,
                    'order_id' => $order->id,
                ],
            ]);
        });

        return redirect()
            ->route('orders.show', $order)
            ->with('success', html_entity_decode('Zam&oacute;wienie zosta&#322;o zaktualizowane.', ENT_QUOTES, 'UTF-8'));
    }

    private function validateOrder(Request $request): array
    {
        $statuses = array_keys(OrderStatusSetting::orderedStatuses());

        $request->merge([
            'source' => $request->input('source', 'manual'),
            'status' => $request->input('status', Order::STATUS_NEW),
        ]);

        return $request->validate([
            'source' => ['required', 'string', 'in:manual,allegro,prestashop'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:'.implode(',', $statuses)],
            'purchased_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'login' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'shipping_name' => ['nullable', 'string', 'max:255'],
            'shipping_company_name' => ['nullable', 'string', 'max:255'],
            'shipping_street' => ['nullable', 'string', 'max:255'],
            'shipping_building_number' => ['nullable', 'string', 'max:255'],
            'shipping_apartment_number' => ['nullable', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['nullable', 'string', 'max:255'],
            'shipping_province' => ['nullable', 'string', 'max:255'],
            'shipping_country_code' => ['nullable', 'string', 'max:2'],
            'billing_name' => ['nullable', 'string', 'max:255'],
            'billing_company_name' => ['nullable', 'string', 'max:255'],
            'billing_tax_id' => ['nullable', 'string', 'max:32'],
            'billing_street' => ['nullable', 'string', 'max:255'],
            'billing_building_number' => ['nullable', 'string', 'max:255'],
            'billing_apartment_number' => ['nullable', 'string', 'max:255'],
            'billing_postal_code' => ['nullable', 'string', 'max:255'],
            'billing_city' => ['nullable', 'string', 'max:255'],
            'billing_province' => ['nullable', 'string', 'max:255'],
            'billing_country_code' => ['nullable', 'string', 'max:2'],
            'billing_phone' => ['nullable', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'total_gross' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost_gross' => ['nullable', 'numeric', 'min:0'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'cash_on_delivery' => ['nullable', 'boolean'],
            'pickup_point_name' => ['nullable', 'string', 'max:255'],
            'pickup_point_id' => ['nullable', 'string', 'max:255'],
            'pickup_point_address' => ['nullable', 'string', 'max:255'],
            'pickup_point_postal_code' => ['nullable', 'string', 'max:255'],
            'pickup_point_city' => ['nullable', 'string', 'max:255'],
            'payment_status' => ['nullable', 'string', 'in:unpaid,paid,refunded'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price_gross' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function orderAddressData(array $validated, string $type): array
    {
        $prefix = $type === 'billing' ? 'billing' : 'shipping';

        $data = [
            $prefix.'_name' => $validated[$prefix.'_name'] ?? null,
            $prefix.'_company_name' => $validated[$prefix.'_company_name'] ?? null,
            $prefix.'_street' => $validated[$prefix.'_street'] ?? null,
            $prefix.'_building_number' => $validated[$prefix.'_building_number'] ?? null,
            $prefix.'_apartment_number' => $validated[$prefix.'_apartment_number'] ?? null,
            $prefix.'_postal_code' => $validated[$prefix.'_postal_code'] ?? null,
            $prefix.'_city' => $validated[$prefix.'_city'] ?? null,
            $prefix.'_province' => $validated[$prefix.'_province'] ?? null,
            $prefix.'_country_code' => $validated[$prefix.'_country_code'] ?? 'PL',
        ];

        if ($type === 'billing') {
            $data['billing_tax_id'] = $validated['billing_tax_id'] ?? null;
        }

        if (array_key_exists($prefix.'_phone', $validated)) {
            $data[$prefix.'_phone'] = PhoneNumberFormatter::normalize($validated[$prefix.'_phone']);
        }

        if (array_key_exists($prefix.'_email', $validated)) {
            $data[$prefix.'_email'] = $validated[$prefix.'_email'];
        }

        return $data;
    }

    private function copyOrderAddressData(Order $order): array
    {
        return $order->only($this->orderAddressFieldNames());
    }

    private function orderAddressFieldNames(): array
    {
        return [
            'shipping_name',
            'shipping_company_name',
            'shipping_street',
            'shipping_building_number',
            'shipping_apartment_number',
            'shipping_postal_code',
            'shipping_city',
            'shipping_province',
            'shipping_country_code',
            'shipping_phone',
            'shipping_email',
            'billing_name',
            'billing_company_name',
            'billing_tax_id',
            'billing_street',
            'billing_building_number',
            'billing_apartment_number',
            'billing_postal_code',
            'billing_city',
            'billing_province',
            'billing_country_code',
            'billing_phone',
            'billing_email',
        ];
    }

    private function orderData(array $validated): array
    {
        return [
            'source' => $validated['source'] ?? 'manual',
            'external_id' => $validated['external_id'] ?? null,
            'status' => $validated['status'] ?? Order::STATUS_NEW,
            'customer_login' => $validated['login'] ?? null,
            'customer_email' => $validated['email'] ?? null,
            'customer_phone' => PhoneNumberFormatter::normalize($validated['phone'] ?? null),
            'currency' => $validated['currency'] ?? 'PLN',
            'total_gross' => $validated['total_gross'] ?? 0,
            'paid_amount' => $validated['paid_amount'] ?? 0,
            'delivery_cost_gross' => $validated['delivery_cost_gross'] ?? 0,
            'shipping_method' => $validated['shipping_method'] ?? null,
            'pickup_point_name' => $validated['pickup_point_name'] ?? null,
            'pickup_point_id' => $validated['pickup_point_id'] ?? null,
            'pickup_point_address' => $validated['pickup_point_address'] ?? null,
            'pickup_point_postal_code' => $validated['pickup_point_postal_code'] ?? null,
            'pickup_point_city' => $validated['pickup_point_city'] ?? null,
            'cash_on_delivery' => (bool) ($validated['cash_on_delivery'] ?? false),
            'payment_status' => $validated['payment_status'] ?? 'unpaid',
            'payment_method' => $validated['payment_method'] ?? null,
            'purchased_at' => $validated['purchased_at'] ?? null,
            'paid_at' => $validated['paid_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    private function syncItems(Order $order, array $items): void
    {
        foreach (array_slice($items, 0, 5) as $item) {
            if (empty($item['product_name'])) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            $unitPriceGross = (float) ($item['unit_price_gross'] ?? 0);

            $order->items()->create([
                'product_name' => $item['product_name'],
                'sku' => null,
                'ean' => null,
                'quantity' => $quantity,
                'unit_price_gross' => $unitPriceGross,
                'total_price_gross' => round($quantity * $unitPriceGross, 2),
            ]);
        }
    }

    private function itemRows(Order $order): array
    {
        $rows = $order->items
            ->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price_gross' => $item->unit_price_gross,
            ])
            ->take(5)
            ->values()
            ->all();

        return array_pad($rows, 5, [
            'product_name' => null,
            'quantity' => 1,
            'unit_price_gross' => 0,
        ]);
    }

    private function emptyItemRows(): array
    {
        return array_fill(0, 5, [
            'product_name' => null,
            'quantity' => 1,
            'unit_price_gross' => 0,
        ]);
    }

    private function validateSelectedOrders(Request $request): array
    {
        return $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'distinct', 'exists:orders,id'],
        ]);
    }

    private function sourceOptions(): array
    {
        return [
            'manual' => html_entity_decode('R&#281;czne', ENT_QUOTES, 'UTF-8'),
            'allegro' => 'Allegro',
            'prestashop' => 'PrestaShop',
        ];
    }

    private function paymentStatusOptions(): array
    {
        return [
            'unpaid' => html_entity_decode('Nieop&#322;acone', ENT_QUOTES, 'UTF-8'),
            'paid' => html_entity_decode('Op&#322;acone', ENT_QUOTES, 'UTF-8'),
            'refunded' => html_entity_decode('Zwr&oacute;cone', ENT_QUOTES, 'UTF-8'),
        ];
    }
}
