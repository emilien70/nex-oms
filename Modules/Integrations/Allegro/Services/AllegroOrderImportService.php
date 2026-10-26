<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroImportException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Products\Models\Product;
use Modules\Products\Services\OrderItemBundleSnapshotService;

class AllegroOrderImportService
{
    public function __construct(
        private readonly AllegroOrderPreviewService $previews,
        private readonly AllegroOrderCatalogService $catalog,
        private readonly OrderItemBundleSnapshotService $bundleSnapshots,
    ) {}

    public function import(AllegroAccount $account, string $identifier, string $expectedHash): Order
    {
        if (! $account->isOperational() || blank($account->allegro_user_id)) {
            throw new AllegroImportException('Połącz konto Allegro przed importem zamówienia.');
        }
        $existing = $this->link($account, $identifier);
        if ($existing !== null) {
            return $this->existingOrder($existing);
        }

        $preview = $this->previews->preview($account, $identifier);
        if (! hash_equals($expectedHash, $preview['source_hash'])) {
            throw new AllegroImportException('Dane zamówienia w Allegro zmieniły się. Sprawdź odświeżony podgląd i ponownie potwierdź import.');
        }
        if (! $preview['importable']) {
            throw new AllegroImportException(implode(' ', $preview['blockers']));
        }

        return $this->persist($account, $preview, false);
    }

    public function synchronize(AllegroAccount $account, array $preview): Order
    {
        return $this->persist($account, $preview, true);
    }

    private function persist(AllegroAccount $account, array $preview, bool $automatic): Order
    {
        $identifier = $preview['external_order_id'];
        $complete = $preview['importable'];
        try {
            return DB::transaction(function () use ($account, $identifier, $preview, $automatic, $complete): Order {
                $locked = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
                if (! $locked->isOperational() || $locked->environment !== $preview['environment']
                    || $locked->allegro_user_id !== $preview['seller_user_id']) {
                    throw new AllegroImportException('Połączenie z kontem Allegro zmieniło się. Pobierz nowy podgląd zamówienia.');
                }
                $existing = $this->link($locked, $identifier);
                if ($existing !== null) {
                    $order = $this->existingOrder($existing);
                    if (! $automatic || $existing->completed_at !== null
                        || in_array($existing->import_state, ['cancelled', 'superseded'], true)) {
                        return $order;
                    }
                    $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                    if ($order->status === Order::STATUS_CANCELLED || $order->invoices()->exists()
                        || $order->shipments()->exists() || $order->shipmentCreationAttempts()->exists()) {
                        throw new AllegroImportException('Zakup byl juz obslugiwany w OMS. Aktualizacja wymaga sprawdzenia.');
                    }
                    if ($existing->source_hash === $preview['source_hash']) {
                        return $order;
                    }
                }
                if (Order::withTrashed()->where('source', 'allegro')->doesntHave('allegroOrderLink')
                    ->whereRaw('LOWER(external_id) = ?', [$identifier])->exists()) {
                    throw new AllegroImportException('W OMS istnieje już zamówienie Allegro z tym identyfikatorem bez bezpiecznego powiązania importu.');
                }

                $catalogAccount = $locked;
                if (! $complete && ($preview['catalog_unavailable'] ?? false)) {
                    $catalogAccount = clone $locked;
                    $catalogAccount->use_linked_products = false;
                }
                $lockedPreview = $this->catalog->apply($catalogAccount, $preview, true);
                if (! hash_equals($preview['catalog_hash'], $lockedPreview['catalog_hash'])) {
                    throw new AllegroImportException('Powiązania, dane produktów lub ustawienia importu zmieniły się. Pobierz nowy podgląd i ponownie potwierdź import.');
                }
                $preview = $lockedPreview;

                $attributes = array_merge(
                    [
                        'source' => 'allegro',
                        'external_id' => $identifier,
                        'status' => Order::STATUS_NEW,
                        'status_changed_at' => now(),
                        'customer_login' => $preview['customer_login'],
                        'customer_email' => $preview['customer_email'],
                        'customer_phone' => $preview['customer_phone'],
                        'currency' => $preview['currency'],
                        'total_gross' => $preview['total_gross'],
                        'paid_amount' => $preview['paid_amount'],
                        'delivery_cost_gross' => $preview['delivery_cost_gross'],
                        'shipping_method' => $preview['shipping_method'],
                        'pickup_point_name' => $preview['pickup_point']['name'],
                        'pickup_point_id' => $preview['pickup_point']['id'],
                        'pickup_point_address' => $preview['pickup_point']['address'],
                        'pickup_point_postal_code' => $preview['pickup_point']['postal_code'],
                        'pickup_point_city' => $preview['pickup_point']['city'],
                        'cash_on_delivery' => $preview['cash_on_delivery'],
                        'payment_status' => $preview['payment_status'],
                        'payment_method' => $preview['payment_method'],
                        'purchased_at' => $preview['purchased_at'],
                        'paid_at' => $preview['paid_at'],
                    ],
                    $this->address('shipping', $preview['shipping_address']),
                    $this->address('billing', $preview['billing_address']),
                );
                if ($existing) {
                    unset($attributes['status'], $attributes['status_changed_at']);
                    $order->fill($attributes)->save();
                    $order->items()->delete();
                } else {
                    $order = Order::query()->create($attributes);
                }

                $link = $existing ?? new AllegroOrderLink;
                $link->fill([
                    'allegro_account_id' => $locked->id,
                    'order_id' => $order->id,
                    'environment' => $locked->environment,
                    'seller_user_id' => $locked->allegro_user_id,
                    'account_name' => $locked->name,
                    'external_order_id' => $identifier,
                    'marketplace_id' => $preview['marketplace_id'],
                    'external_status' => $preview['external_status'],
                    'fulfillment_status' => $preview['fulfillment_status'],
                    'revision' => $preview['revision'],
                    'source_hash' => $preview['source_hash'],
                    'invoice_required' => $preview['invoice_required'],
                    'smart' => $preview['smart'],
                    'delivery_method_id' => $preview['delivery_method_id'],
                    'declared_packages' => $preview['declared_packages'],
                    'buyer_message' => $preview['buyer_message'],
                    'external_updated_at' => $preview['external_updated_at'],
                    'imported_at' => $existing?->imported_at ?? now(),
                    'details_snapshot' => $preview['details_snapshot'],
                    'details_refreshed_at' => now(),
                    'import_state' => $complete ? 'complete' : (filled($preview['processing_error'] ?? null) ? 'review' : 'incomplete'),
                    'completed_at' => $complete ? now() : null,
                    'processing_error' => $preview['processing_error'] ?? null,
                    'delivery_cost_known' => $preview['delivery_cost_known'] ?? true,
                    'line_item_ids' => array_values(array_filter(array_column($preview['items'], 'external_id'), fn ($id) => Str::isUuid($id))),
                    'buyer_name' => $preview['buyer_name'] ?? $existing?->buyer_name,
                ])->save();
                foreach ($preview['items'] as $item) {
                    $orderItem = $order->items()->create(collect($item)->only([
                        'external_id', 'product_name', 'offer_id', 'quantity', 'unit_price_gross',
                        'total_price_gross', 'currency', 'product_id', 'vat_rate', 'weight',
                    ])->all());
                    if ($item['product_id'] !== null && $item['catalog']['item_type'] === Product::TYPE_BUNDLE) {
                        $this->bundleSnapshots->capture($orderItem, Product::query()->findOrFail($item['product_id']));
                    }
                }
                foreach ($preview['payments'] as $payment) {
                    $link->payments()->updateOrCreate(['external_payment_id' => $payment['external_payment_id']], $payment + [
                        'accounted_at' => $payment['amount'] !== null && $payment['paid_at'] !== null ? now() : null,
                    ]);
                }
                $order->events()->create([
                    'event_type' => $existing ? ($complete ? 'allegro_order_completed' : 'allegro_order_updated') : 'allegro_order_imported',
                    'title' => $existing ? ($complete ? 'Pobrano kompletne dane Allegro' : 'Zaktualizowano zakup Allegro') : 'Zaimportowano zamówienie z Allegro',
                    'description' => ($automatic ? 'Automatyczne pobranie ' : 'Ręczny import zamówienia ').$identifier.'. '.($complete ? 'Kompletne dane.' : 'Zakup wymaga dokonczenia lub sprawdzenia.')
                        .(collect($preview['items'])->contains(fn (array $item): bool => $item['vat_resolution']['source'] === 'default') ? ' Zastosowano domyślną stawkę VAT integracji.' : ''),
                    'payload' => [
                        'allegro_account_id' => $locked->id,
                        'external_order_id' => $identifier,
                        'source_hash' => $preview['source_hash'],
                        'vat_sources' => array_map(fn (array $item): array => [
                            'external_id' => $item['external_id'],
                            'channel_rate' => $item['source_vat']['rate'],
                            ...$item['vat_resolution'],
                        ], $preview['items']),
                    ],
                ]);

                return $order->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->link($account, $identifier);
            if ($existing === null) {
                throw $exception;
            }

            return $this->existingOrder($existing);
        }
    }

    private function link(AllegroAccount $account, string $identifier): ?AllegroOrderLink
    {
        return AllegroOrderLink::query()->where('environment', $account->environment)
            ->where('seller_user_id', $account->allegro_user_id)->where('external_order_id', $identifier)->first();
    }

    private function existingOrder(AllegroOrderLink $link): Order
    {
        if ($link->order === null) {
            throw new AllegroImportException('To zamówienie było już importowane, ale jego rekord OMS został trwale usunięty.');
        }
        if ($link->order->trashed()) {
            throw new AllegroImportException('To zamówienie Allegro znajduje się już w koszu OMS. Przywróć istniejące zamówienie.');
        }

        return $link->order;
    }

    private function address(string $prefix, array $address): array
    {
        $data = [];
        foreach ($address as $key => $value) {
            if ($key !== 'tax_id' || $prefix === 'billing') {
                $data[$prefix.'_'.$key] = $value;
            }
        }

        return $data;
    }
}
