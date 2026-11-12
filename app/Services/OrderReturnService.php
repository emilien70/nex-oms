<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\OrderReturnParcelEvent;
use App\Models\ReturnStatusSetting;
use App\Support\CountryCatalog;
use App\Support\CurrencyCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Automation\Models\AutomationRun;
use Modules\Integrations\Allegro\Models\AllegroCommissionRefundClaim;
use Modules\Integrations\Allegro\Models\AllegroRefundCommand;

class OrderReturnService
{
    public const STAR_COLORS = ['', 'orange', 'navy', 'green', 'blue', 'red'];

    public function fingerprint(Order $order): string
    {
        $order->loadMissing(['items', 'allegroOrderLink', 'prestashopOrderLinks.account']);

        return hash('sha256', json_encode([$order->getAttributes(), $order->items->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    public function claimedQuantity(int $orderItemId, ?int $exceptReturn = null): int
    {
        return (int) OrderReturnItem::query()->where('order_item_id', $orderItemId)
            ->when($exceptReturn, fn ($q) => $q->where('order_return_id', '!=', $exceptReturn))
            ->whereHas('orderReturn', fn ($q) => $q->whereHas('localStatus', fn ($s) => $s->where('code', '!=', 'rejected')))->sum('quantity');
    }

    public function create(array $data): OrderReturn
    {
        $intent = hash('sha256', json_encode(Arr::except($data, ['request_key']), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $intent): OrderReturn {
            $initial = ReturnStatusSetting::query()->where('code', 'new')->lockForUpdate()->firstOrFail();
            $existing = OrderReturn::query()->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->creation_fingerprint !== $intent) {
                    $this->invalid('Formularz został już zapisany z innymi danymi. Otwórz nowy formularz.');
                }

                return $existing;
            }
            $order = null;
            if (! empty($data['order_id'])) {
                $order = Order::query()->lockForUpdate()->find($data['order_id']);
                if (! $order) {
                    $this->invalid('Zamówienie nie istnieje lub znajduje się w koszu.');
                }
                $order->load(['items', 'allegroOrderLink', 'prestashopOrderLinks.account']);
                if (! hash_equals($this->fingerprint($order), (string) ($data['order_fingerprint'] ?? ''))) {
                    $this->invalid('Dane zamówienia zmieniły się. Otwórz ponownie formularz zwrotu.');
                }
            }
            $currency = $order?->currency ?? strtoupper(trim((string) ($data['currency'] ?? '')));
            if (! app(CurrencyCatalog::class)->isAllowed($currency, $order?->currency)) {
                $this->invalid('Wybierz prawidłową walutę.');
            }
            $address = $order ? (array) $order->shippingAddressData() : ($data['address'] ?? []);
            if ($order) {
                $address += ['order_id' => $order->id, 'order_external_id' => $order->external_id];
                if ($identity = app(OrderReturnRefundService::class)->orderIdentity($order)) {
                    $address['allegro_identity'] = $identity;
                }
            } else {
                $address['name'] ??= $data['buyer_name'];
            }
            if (! $order && filled($address['country_code'] ?? null)) {
                if (! app(CountryCatalog::class)->exists($address['country_code'])) {
                    $this->invalid('Wybierz prawidłowy kraj.');
                }
                $address['country_code'] = app(CountryCatalog::class)->normalize($address['country_code']);
            }
            $items = [];
            $seen = [];
            $total = BigDecimal::zero()->toScale(2);
            foreach ($data['items'] as $position => $row) {
                $quantity = (int) $row['quantity'];
                if ($quantity === 0) {
                    continue;
                }
                $original = null;
                if ($order) {
                    $id = (int) ($row['order_item_id'] ?? 0);
                    $original = $order->items->firstWhere('id', $id);
                    if (! $original || isset($seen[$id])) {
                        $this->invalid('Wybierz niepowtarzające się produkty tego zamówienia.');
                    }
                    $seen[$id] = true;
                    if ($quantity > (int) $original->quantity - $this->claimedQuantity($id)) {
                        $this->invalid('Ilość zwrotu przekracza liczbę pozostałych produktów: '.$original->product_name);
                    }
                    if ($original->currency !== $currency) {
                        $this->invalid('Pozycja zamówienia ma inną walutę.');
                    }
                }
                $price = $original?->unit_price_gross ?? ($row['unit_price'] ?? null);
                $name = $original?->product_name ?? trim((string) ($row['name'] ?? ''));
                if (! is_string($price) || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $price) || $name === '') {
                    $this->invalid('Uzupełnij nazwę i cenę każdej zwracanej pozycji.');
                }
                $value = BigDecimal::of($price)->toScale(2)->multipliedBy($quantity);
                $total = $total->plus($value);
                if ($total->isGreaterThan('9999999999999999.99')) {
                    $this->invalid('Wartość zwrotu jest zbyt duża.');
                }
                $items[] = ['position' => count($items), 'order_item_id' => $original?->id,
                    'product_id' => $original?->product_id ?? ($order ? null : ($row['product_id'] ?? null)),
                    'offer_id' => $original?->offer_id, 'name' => $name, 'quantity' => $quantity,
                    'unit_price' => (string) BigDecimal::of($price)->toScale(2), 'total_value' => (string) $value, 'currency' => $currency,
                    'reason' => trim((string) ($row['reason'] ?? '')) ?: 'NONE', 'comment' => $row['comment'] ?? null];
            }
            if ($items === []) {
                $this->invalid('Wybierz przynajmniej jeden produkt i dodatnią ilość.');
            }
            $sourceName = $order ? match ($order->source) {
                'allegro' => $order->allegroOrderLink?->account_name,
                'prestashop' => $order->prestashopOrderLinks->first()?->account?->name,
                default => null,
            } : null;
            $record = OrderReturn::query()->create([
                'creation_method' => 'manual', 'source' => $order?->source ?: 'manual', 'account_name' => $sourceName,
                'order_id' => $order?->id, 'reference_number' => $data['reference_number'] ?? null, 'notes' => $data['notes'] ?? null,
                'buyer_name' => $order?->shipping_name ?: ($order ? $order->customer_login : $data['buyer_name']),
                'buyer_login' => $order ? $order->customer_login : ($data['buyer_login'] ?? null),
                'buyer_email' => $order ? $order->customer_email : ($data['buyer_email'] ?? null),
                'buyer_phone' => $order ? $order->customer_phone : ($data['buyer_phone'] ?? null),
                'currency' => $currency, 'total_value' => (string) $total, 'submitted_at' => now(), 'order_snapshot' => $address,
                'local_status_id' => $initial->id, 'local_status_changed_at' => now(),
                'request_key' => $data['request_key'], 'creation_fingerprint' => $intent,
            ]);
            $record->items()->createMany($items);
            $record->events()->create(['type' => 'manual_created', 'status' => $initial->name, 'observed_at' => now()]);

            return $record;
        }, 3);
    }

    public function changeStatuses(array $versions, int $statusId): void
    {
        DB::transaction(function () use ($versions, $statusId): void {
            ReturnStatusSetting::query()->where('code', 'new')->lockForUpdate()->firstOrFail();
            $target = ReturnStatusSetting::query()->lockForUpdate()->findOrFail($statusId);
            // One lock order is shared with creation, so quantity reservations cannot race.
            $ids = array_keys($versions);
            sort($ids);
            $records = OrderReturn::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($records->count() !== count($versions)) {
                $this->invalid('Lista zwrotów zmieniła się. Odśwież stronę.');
            }
            foreach ($records as $record) {
                if ($record->lock_version !== (int) $versions[$record->id]) {
                    $this->invalid('Zwrot zmienił się w innym oknie. Odśwież stronę.');
                }
                if ($record->local_status_id === $target->id) {
                    continue;
                }
                if ($record->creation_method === 'manual' && $record->localStatus?->code === 'rejected' && $target->code !== 'rejected') {
                    foreach ($record->items()->whereNotNull('order_item_id')->with('orderItem')->get() as $item) {
                        if (! $item->orderItem || $item->quantity + $this->claimedQuantity($item->order_item_id, $record->id) > (int) $item->orderItem->quantity) {
                            $this->invalid('Nie można ponownie otworzyć zwrotu: ilość jest już objęta innym zgłoszeniem.');
                        }
                    }
                }
                $record->events()->create(['type' => 'local_status_changed', 'previous_status' => $record->localStatus?->name,
                    'status' => $target->name, 'observed_at' => now()]);
                $record->update(['local_status_id' => $target->id, 'local_status_changed_at' => now(), 'lock_version' => $record->lock_version + 1]);
            }
        }, 3);
    }

    public function deleteUnprocessed(array $versions): void
    {
        DB::transaction(function () use ($versions): void {
            // Match the lock order of return creation and local status changes.
            ReturnStatusSetting::query()->where('code', 'new')->lockForUpdate()->firstOrFail();
            $ids = array_keys($versions);
            $records = OrderReturn::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $records->load('localStatus')->loadExists([
                'correction',
                'refundAllocations',
                'parcels as tracked_parcel_exists' => fn ($query) => $query->whereNotNull('tracking_status'),
                'events as financial_history_exists' => fn ($query) => $query->whereIn('type', [
                    'correction_issued', 'correction_finalized', 'correction_deleted',
                    'refund_allocation_changed', 'allegro_refund_command', 'allegro_commission_claim',
                ]),
            ])->loadCount([
                'events as status_changes_count' => fn ($query) => $query->where('type', 'local_status_changed'),
            ]);
            $refunds = $this->financialReferences(AllegroRefundCommand::query()->where('state', '!=', 'draft'), $ids);
            $claims = $this->financialReferences(AllegroCommissionRefundClaim::query(), $ids);
            $tracked = OrderReturnParcelEvent::query()->whereIn('order_return_id', $ids)->distinct()->pluck('order_return_id')->flip();
            $automated = AutomationRun::query()->whereIn('order_return_id', $ids)->distinct()->pluck('order_return_id')->flip();

            $errors = [];
            foreach ($versions as $id => $version) {
                $record = $records->get($id);
                $status = $record?->localStatus?->code;
                $reason = match (true) {
                    $record === null => 'już nie istnieje. Odśwież listę.',
                    $record->lock_version !== (int) $version => 'zmienił się w innym oknie. Odśwież listę.',
                    $record->creation_method !== 'manual' || filled($record->external_return_id) => 'został zaimportowany. Usuwanie zwrotów z integracji nie jest dostępne.',
                    (bool) $record->correction_exists => 'ma powiązaną korektę.',
                    (bool) $record->refund_allocations_exists || isset($refunds[$id]) => 'ma przypisaną lub zleconą refundację.',
                    isset($claims[$id]) => 'ma wniosek o zwrot prowizji.',
                    (bool) $record->financial_history_exists => 'ma historię operacji finansowych.',
                    (bool) $record->tracked_parcel_exists || isset($tracked[$id]) => 'ma historię śledzenia przesyłki zwrotnej.',
                    isset($automated[$id]) => 'ma powiązane wykonanie automatyzacji.',
                    ! in_array($status, ['new', 'rejected'], true) => 'nie jest nowy ani odrzucony.',
                    $record->status_changes_count > ($status === 'rejected' ? 1 : 0) => 'ma wcześniejszą historię obsługi.',
                    default => null,
                };
                if ($reason !== null) {
                    $errors[] = "Zwrot #{$id} {$reason}";
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['return' => $errors]);
            }

            foreach ($records as $record) {
                $record->delete();
            }
        }, 3);
    }

    private function financialReferences(Builder $query, array $ids): array
    {
        return $query->where(fn ($query) => $query->whereIn('order_return_id', $ids)->orWhereIn('return_number', $ids))
            ->get(['order_return_id', 'return_number'])
            ->flatMap(fn ($record) => [$record->order_return_id, $record->return_number])
            ->filter()->flip()->all();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['return' => $message]);
    }
}
