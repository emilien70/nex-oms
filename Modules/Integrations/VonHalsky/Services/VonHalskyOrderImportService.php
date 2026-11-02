<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderLink;
use Modules\Products\Models\Product;
use Modules\Products\Services\OrderItemBundleSnapshotService;

class VonHalskyOrderImportService
{
    public function __construct(
        private readonly VonHalskyOrderPreviewService $previews,
        private readonly VonHalskyAccountService $accounts,
        private readonly VonHalskyOrderCatalogService $catalog,
        private readonly OrderItemBundleSnapshotService $bundles,
    ) {}

    public function import(VonHalskyAccount $account, string $id, string $expectedHash, array $quantities,
        bool $confirmedQuantities): Order
    {
        if (! $confirmedQuantities) {
            throw new VonHalskyApiException('Potwierdź ilości importowanych pozycji.');
        }
        $current = $this->accounts->current($account);
        if (! $current->is_active || $current->last_test_status !== 'success') {
            throw new VonHalskyApiException('Połączenie Von Halsky nie jest aktywne.');
        }
        if ($existing = $this->link($account, $id)) {
            return $this->existingOrder($existing);
        }
        $preview = $this->previews->preview($account, $id, $quantities)['import'];
        if (! is_string($preview['source_hash']) || ! hash_equals($expectedHash, $preview['source_hash'])) {
            throw new VonHalskyApiException('Dane źródłowe lub ustawienia zmieniły się. Pobierz nowy podgląd i potwierdź import.');
        }
        if (! $preview['importable']) {
            throw new VonHalskyApiException(implode(' ', $preview['blockers']) ?: 'Uzupełnij i potwierdź ilości wszystkich pozycji.');
        }

        return $this->persist($account, $id, $preview);
    }

    public function importAutomatic(VonHalskyAccount $account, VonHalskyOrderImportAttempt $attempt, string $token,
        string $expectedHash, bool $manualScan = false): Order
    {
        $preview = $this->previews->preview($account, $attempt->external_order_id)['import'];
        if (! $preview['importable'] || ! hash_equals($expectedHash, (string) $preview['source_hash'])
            || $preview['requires_quantities'] || collect($preview['items'])->contains(fn ($item) => $item['quantity_source'] !== 'api')
            || ! $account->order_import_started_at || $preview['attributes']['purchased_at']->lt($account->order_import_started_at)) {
            throw new VonHalskyApiException('Automatyczny import wymaga aktualnych danych i jednoznacznych ilosci z API.');
        }

        return $this->persist($account, $attempt->external_order_id, $preview,
            ['attempt_id' => $attempt->id, 'token' => $token, 'revision' => (int) $account->order_import_revision, 'manual' => $manualScan]);
    }

    private function persist(VonHalskyAccount $account, string $id, array $preview, ?array $automatic = null): Order
    {
        try {
            return DB::transaction(function () use ($account, $id, $preview, $automatic): Order {
                $locked = $this->accounts->current($account, lock: true);
                if (! $locked->is_active || $locked->lock_version !== $account->lock_version) {
                    throw new VonHalskyApiException('Konfiguracja importu została zmieniona. Pobierz nowy podgląd.');
                }
                if ($automatic !== null) {
                    $state = app(VonHalskyOrderImportState::class);
                    $attempt = VonHalskyOrderImportAttempt::query()->lockForUpdate()->find($automatic['attempt_id']);
                    if (! $state->account($locked->id, $automatic['revision'], $automatic['manual']) || ! $attempt
                        || ! $state->owns($locked, $attempt) || $attempt->status !== 'processing'
                        || $attempt->processing_token !== $automatic['token'] || ! $attempt->processing_expires_at?->isFuture()) {
                        throw new VonHalskyApiException('Zlecenie importu wygaslo lub konfiguracja zostala zmieniona.');
                    }
                }
                if ($existing = $this->link($locked, $id)) {
                    return $this->existingOrder($existing);
                }
                $lockedPreview = $this->catalog->apply($locked, $preview, lock: true);
                if (! hash_equals($preview['catalog_hash'], $lockedPreview['catalog_hash']) || $lockedPreview['catalog_blockers'] !== []) {
                    throw new VonHalskyApiException('Powiązania lub dane katalogu zmieniły się. Pobierz nowy podgląd i potwierdź import.');
                }
                $preview = $lockedPreview;
                $status = OrderStatusSetting::query()->where('status', $preview['attributes']['status'])->lockForUpdate()->first();
                if (! $status) {
                    throw new VonHalskyApiException('Status początkowy nie jest już dostępny. Wybierz status i pobierz nowy podgląd.');
                }
                if (Order::withTrashed()->where('source', 'von_halsky')->where('external_id', $id)->doesntHave('vonHalskyOrderLink')->exists()) {
                    throw new VonHalskyApiException('Istnieje zamówienie z tym numerem bez bezpiecznego powiązania importu. Sprawdź je ręcznie.');
                }
                $order = Order::query()->create($preview['attributes'] + ['status_changed_at' => now()]);
                foreach ($preview['items'] as $item) {
                    $orderItem = $order->items()->create(collect($item)->only([
                        'external_id', 'offer_id', 'product_id', 'product_name', 'quantity',
                        'unit_price_gross', 'total_price_gross', 'currency', 'vat_rate', 'weight',
                    ])->all());
                    if ($item['product_id'] !== null && $item['catalog']['item_type'] === Product::TYPE_BUNDLE) {
                        $this->bundles->capture($orderItem, Product::query()->findOrFail($item['product_id']));
                    }
                }
                VonHalskyOrderLink::query()->create([
                    'von_halsky_account_id' => $locked->id, 'order_id' => $order->id,
                    'environment' => $locked->environment->value, 'organization_id' => $locked->organization_id,
                    'external_order_id' => $id, 'account_name' => $locked->name,
                    'source_hash' => $preview['source_hash'], 'external_status' => $preview['external_status'],
                    'invoice_required' => $preview['invoice_required'], 'buyer_name' => $preview['buyer_name'],
                    'buyer_message' => $preview['buyer_message'], 'payment_evidence' => $preview['payment_evidence'],
                    'channel_paid_amount' => $order->paid_amount, 'channel_paid_at' => $order->paid_at,
                    'quantities_snapshot' => array_map(fn (array $item): array => [
                        'line_index' => $item['external_id'], 'quantity' => $item['quantity'], 'source' => $item['quantity_source'],
                    ], $preview['items']),
                    'automations_suppressed' => false, 'imported_at' => now(),
                ]);
                $order->events()->create([
                    'event_type' => 'von_halsky_order_imported', 'title' => 'Zaimportowano zamówienie InPost Von Halsky',
                    'description' => $automatic === null
                        ? 'Ręczny import '.$id.'. Ilości potwierdzono.'
                        : 'Automatyczny import '.$id.'. Ilości pochodzą z API.',
                    'payload' => ['external_order_id' => $id, 'source_hash' => $preview['source_hash'],
                        'automations_suppressed' => false,
                        'import_mode' => $automatic === null ? 'manual' : 'automatic',
                        'vat_sources' => array_column($preview['items'], 'vat_source')],
                ]);
                if (app(VonHalskyOrderImportState::class)->ready()) {
                    VonHalskyOrderImportAttempt::query()->where('von_halsky_account_id', $locked->id)
                        ->where('environment', $locked->environment->value)->where('organization_id', $locked->organization_id)
                        ->where('external_order_id', $id)->update(['order_id' => $order->id, 'status' => 'imported',
                            'processing_token' => null, 'processing_expires_at' => null, 'next_attempt_at' => null,
                            'last_message' => null, 'last_attempt_at' => now()]);
                }

                $order->refresh();
                OrderCreated::dispatch($order);
                OrderDataCompleted::dispatch($order);

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($existing = $this->link($account, $id)) {
                return $this->existingOrder($existing);
            }

            throw $exception;
        }
    }

    public function link(VonHalskyAccount $account, string $id): ?VonHalskyOrderLink
    {
        return VonHalskyOrderLink::query()->where('environment', $account->environment->value)
            ->where('organization_id', $account->organization_id)->where('external_order_id', $id)->first();
    }

    private function existingOrder(VonHalskyOrderLink $link): Order
    {
        $order = $link->order_id ? Order::withTrashed()->find($link->order_id) : null;
        if (! $order || $order->trashed()) {
            throw new VonHalskyApiException('Zamówienie było już importowane i zostało usunięte lub przeniesione do Kosza. Nie zostanie utworzone ponownie.');
        }
        if ($order->source !== 'von_halsky' || $order->external_id !== $link->external_order_id) {
            throw new VonHalskyApiException('Powiązanie istniejącego zamówienia wymaga ręcznego sprawdzenia.');
        }

        return $order;
    }
}
