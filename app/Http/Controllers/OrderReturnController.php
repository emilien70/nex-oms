<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderReturnRequest;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use App\Services\OrderReturnService;
use App\Support\CountryCatalog;
use App\Support\CurrencyCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Support\AllegroShipmentNumbers;
use Modules\Invoices\Services\InvoiceMoneyFormatter;

class OrderReturnController extends Controller
{
    public function index(Request $request, InvoiceMoneyFormatter $money): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:100'],
            'account' => ['nullable', 'string', 'max:255'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'in:20,30,50,75,100,150,200'],
            'return_status' => ['nullable', 'integer'], 'source' => ['nullable', 'string', 'max:30'],
            'creation_method' => ['nullable', 'in:manual,allegro_import'], 'starred' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:newest,oldest,amount_desc,amount_asc'],
        ]);
        $query = OrderReturn::query()->with(['items', 'order', 'localStatus', 'parcels']);
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($query) use ($q): void {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                foreach (['reference_number', 'external_return_id', 'external_order_id', 'buyer_name', 'buyer_login', 'buyer_email'] as $field) {
                    $query->orWhereRaw($field." LIKE ? ESCAPE '\\'", [$pattern]);
                }
                if (ctype_digit($q)) {
                    $query->orWhere('id', $q)->orWhere('order_id', $q);
                }
                $query->orWhereHas('items', fn ($items) => $items->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern]));
                $query->orWhereHas('parcels', fn ($parcels) => $parcels->whereRaw("waybill LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("transporting_waybill LIKE ? ESCAPE '\\'", [$pattern]));
                $query->orWhereHas('order.shipments', fn ($shipments) => $shipments->whereRaw("tracking_number LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereHas('parcels', fn ($parcels) => $parcels->whereRaw("tracking_number LIKE ? ESCAPE '\\'", [$pattern])));
            });
        }
        $query->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['return_status'] ?? null, fn ($q, $value) => $q->where('local_status_id', $value))
            ->when($filters['source'] ?? null, fn ($q, $value) => $q->where('source', $value))
            ->when($filters['creation_method'] ?? null, fn ($q, $value) => $q->where('creation_method', $value))
            ->when($filters['starred'] ?? false, fn ($q) => $q->whereNotNull('star_color'))
            ->when($filters['account'] ?? null, fn ($q, $value) => $q->where('account_name', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->where('submitted_at', '>=', CarbonImmutable::parse($value, config('app.timezone'))->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->where('submitted_at', '<=', CarbonImmutable::parse($value, config('app.timezone'))->endOfDay()->utc()));
        $perPage = (int) ($filters['per_page'] ?? 20);
        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('submitted_at')->orderBy('id'),
            'amount_desc' => $query->orderBy('currency')->orderByDesc('total_value')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('currency')->orderBy('total_value')->orderBy('id'),
            default => $query->orderByDesc('submitted_at')->orderByDesc('id'),
        };

        return view('returns.index', [
            'returns' => $query->paginate($perPage)->withQueryString(), 'filters' => $filters, 'perPage' => $perPage,
            'counts' => OrderReturn::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'accountNames' => OrderReturn::query()->distinct()->orderBy('account_name')->pluck('account_name'),
            'syncAccounts' => AllegroAccount::query()->get(), 'money' => $money,
            'returnStatuses' => ReturnStatusSetting::query()->orderBy('sort_order')->orderBy('id')->get(),
            'starColors' => OrderReturnService::STAR_COLORS,
        ]);
    }

    public function show(OrderReturn $orderReturn, InvoiceMoneyFormatter $money, CountryCatalog $countries): View
    {
        $orderReturn->load(['localStatus', 'items.product', 'parcels', 'order.allegroOrderLink',
            'order.shipments.courierAccount', 'order.shipments.parcels', 'order.invoices']);
        $order = $orderReturn->order;
        $address = $order ? $order->shippingAddressData() : (object) ($orderReturn->order_snapshot ?? []);
        $previous = OrderReturn::query()->where('id', '<', $orderReturn->id)->orderByDesc('id')->value('id');
        $next = OrderReturn::query()->where('id', '>', $orderReturn->id)->orderBy('id')->value('id');
        $events = $orderReturn->events()->paginate(20, ['*'], 'history_page');
        $messages = $order?->emailMessages()->latest('id')->limit(20)->get() ?? collect();
        $orderShipments = collect();
        foreach ($order?->shipments ?? [] as $shipment) {
            $numbers = AllegroShipmentNumbers::current($shipment);
            foreach ($shipment->parcels->pluck('tracking_number')->push($shipment->tracking_number)->filter() as $waybill) {
                if (! in_array($waybill, array_column($numbers, 'waybill'), true)) {
                    $numbers[] = ['carrier_id' => $shipment->carrier_code ?: $shipment->provider, 'waybill' => $waybill];
                }
            }
            foreach ($numbers as $number) {
                $orderShipments->push(['shipment' => $shipment, 'carrier' => $number['carrier_id'], 'waybill' => $number['waybill']]);
            }
        }

        $returnStatuses = ReturnStatusSetting::query()->orderBy('sort_order')->orderBy('id')->get();

        return view('returns.show', compact('orderReturn', 'order', 'address', 'money', 'countries', 'previous', 'next', 'events', 'messages', 'orderShipments', 'returnStatuses'));
    }

    public function create(Request $request, OrderReturnService $service, CurrencyCatalog $currencies, CountryCatalog $countries)
    {
        $request->validate(['order_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:255']]);
        $order = $request->filled('order_id') ? Order::query()->with(['items', 'returns.items', 'returns.localStatus', 'allegroOrderLink', 'prestashopOrderLinks.account'])->findOrFail($request->integer('order_id')) : null;
        $candidates = collect();
        if (! $order && $request->filled('q')) {
            $q = trim($request->string('q')->toString());
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $candidates = Order::query()->where(function ($query) use ($q, $pattern): void {
                if (ctype_digit($q)) {
                    $query->orWhere('id', $q);
                }
                foreach (['external_id', 'shipping_name', 'customer_login', 'customer_email'] as $column) {
                    $query->orWhereRaw($column." LIKE ? ESCAPE '\\'", [$pattern]);
                }
            })->latest('id')->limit(20)->get();
        }
        $remaining = $order?->items->mapWithKeys(fn ($item) => [$item->id => max(0, (int) $item->quantity - $service->claimedQuantity($item->id))]) ?? collect();

        return view('returns.create', ['order' => $order, 'candidates' => $candidates, 'remaining' => $remaining,
            'requestKey' => (string) Str::uuid(), 'orderFingerprint' => $order ? $service->fingerprint($order) : null,
            'currencies' => $currencies->all(), 'countries' => $countries->all()]);
    }

    public function store(StoreOrderReturnRequest $request, OrderReturnService $service)
    {
        return redirect()->route('returns.show', $service->create($request->validated()));
    }

    public function status(Request $request, OrderReturn $orderReturn, OrderReturnService $service)
    {
        $data = $request->validate(['local_status_id' => ['required', 'integer', 'exists:return_status_settings,id'], 'expected_lock_version' => ['required', 'integer', 'min:0']]);
        $service->changeStatuses([$orderReturn->id => $data['expected_lock_version']], (int) $data['local_status_id']);

        return back();
    }

    public function bulkStatus(Request $request, OrderReturnService $service)
    {
        $data = $request->validate(['return_ids' => ['required', 'array', 'min:1', 'max:200'], 'return_ids.*' => ['required', 'integer', 'distinct'],
            'versions' => ['required', 'array'], 'versions.*' => ['required', 'integer', 'min:0'], 'local_status_id' => ['required', 'integer', 'exists:return_status_settings,id']]);
        $versions = array_intersect_key($data['versions'], array_flip($data['return_ids']));
        if (count($versions) !== count($data['return_ids'])) {
            throw ValidationException::withMessages(['return' => 'Odśwież listę zwrotów i spróbuj ponownie.']);
        }
        $service->changeStatuses($versions, (int) $data['local_status_id']);

        return back();
    }

    public function star(Request $request, OrderReturn $orderReturn)
    {
        $data = $request->validate(['star_color' => ['nullable', Rule::in(OrderReturnService::STAR_COLORS)], 'expected_lock_version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($data, $orderReturn): void {
            $record = OrderReturn::query()->lockForUpdate()->findOrFail($orderReturn->id);
            if ($record->lock_version !== (int) $data['expected_lock_version']) {
                throw ValidationException::withMessages(['return' => 'Zwrot zmienił się. Odśwież stronę.']);
            }
            $color = ($data['star_color'] ?? '') ?: null;
            if ($record->star_color !== $color) {
                $record->update(['star_color' => $color, 'lock_version' => $record->lock_version + 1]);
            }
        });

        return back();
    }
}
