<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Enums\PrestashopCatalogVatMode;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopProductLink;
use Modules\Integrations\Services\OrderImportVatResolver;
use Modules\Products\Models\Product;
use Modules\Products\Services\OrderItemBundleSnapshotService;

class PrestashopOrderImportService
{
    public function __construct(
        private readonly PrestashopAccountService $accountService,
        private readonly PrestashopOrderPreviewService $previewService,
        private readonly OrderItemBundleSnapshotService $bundleSnapshots,
        private readonly OrderImportVatResolver $vat,
        private readonly PrestashopPaymentStatusService $paymentStatuses,
        private readonly PrestashopOrderMessageService $buyerMessages,
    ) {}

    public function import(string $identifier): Order
    {
        $account = $this->accountService->get();

        return $this->importForAccount($account, $identifier);
    }

    public function importForAccount(PrestashopAccount $account, string $identifier): Order
    {
        if (! $account->is_active) {
            throw new PrestashopImportException('Włącz integrację PrestaShop przed importem zamówienia.');
        }

        $preview = $this->previewService->preview($account, $identifier);

        return DB::transaction(function () use ($account, $preview): Order {
            $lockedAccount = PrestashopAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedAccount->is_active) {
                throw new PrestashopImportException('Integracja PrestaShop została wyłączona przed zakończeniem importu.');
            }

            $existingLink = PrestashopOrderLink::query()
                ->where('prestashop_account_id', $lockedAccount->getKey())
                ->where('external_order_id', $preview['external_order_id'])
                ->lockForUpdate()
                ->first();

            if ($existingLink) {
                $existingOrder = $existingLink->order;

                if (! $existingOrder) {
                    throw new PrestashopImportException(
                        'To zamówienie było już importowane, ale jego rekord OMS został trwale usunięty.',
                    );
                }

                if ($existingOrder->trashed()) {
                    throw new PrestashopImportException('To zamówienie PrestaShop znajduje się już w koszu OMS.');
                }

                $this->buyerMessages->enqueue($existingLink->id);

                return $this->completeExistingOrder(
                    $existingOrder,
                    $existingLink,
                    $lockedAccount,
                    $preview,
                );
            }

            if (Order::withTrashed()
                ->where('source', 'prestashop')
                ->where('external_id', $preview['reference'])
                ->exists()) {
                throw new PrestashopImportException(
                    'W OMS istnieje już zamówienie PrestaShop z tym numerem, ale bez bezpiecznego powiązania importu.',
                );
            }

            $order = Order::query()->create(array_merge([
                'source' => 'prestashop',
                'external_id' => $preview['reference'],
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
                'notes' => $preview['notes'],
            ], $this->addressData('shipping', $preview['shipping_address']), $this->addressData('billing', $preview['billing_address'])));

            $link = PrestashopOrderLink::query()->create([
                'prestashop_account_id' => $lockedAccount->getKey(),
                'order_id' => $order->getKey(),
                'external_order_id' => $preview['external_order_id'],
                'external_reference' => $preview['reference'],
                'external_order_state_id' => $preview['external_status_id'],
                'external_order_state_name' => $preview['external_status_name'],
                'external_created_at' => $preview['purchased_at'],
                'external_updated_at' => $preview['external_updated_at'],
                'source_hash' => $preview['source_hash'],
                'imported_at' => now(),
            ]);

            $linkedProducts = $this->linkedProductsByExternalId(
                $lockedAccount,
                array_column($preview['items'], 'external_product_id'),
            );
            $linkedProductCount = 0;
            $vatSources = [];
            $domestic = $preview['shipping_address']['country_code'] === 'PL'
                && $preview['billing_address']['country_code'] === 'PL';

            foreach ($preview['items'] as $itemData) {
                $externalProductId = $itemData['external_product_id'];
                $linkedProduct = $externalProductId === null
                    ? null
                    : ($linkedProducts[$externalProductId] ?? null);
                $resolved = $this->productValues($lockedAccount, $itemData, $linkedProduct, $domestic);
                $vatSources[] = ['external_id' => (string) $itemData['external_order_detail_id'],
                    'channel_rate' => $itemData['vat_rate'], ...$resolved['vat_resolution']];
                $productValues = $resolved['values'];

                $orderItem = $order->items()->create([
                    'product_id' => $productValues['product_id'],
                    'external_id' => (string) $itemData['external_order_detail_id'],
                    'product_name' => $productValues['product_name'],
                    'sku' => $itemData['sku'],
                    'ean' => $itemData['ean'],
                    'offer_id' => null,
                    'quantity' => $itemData['quantity'],
                    'unit_price_gross' => $itemData['unit_price_gross'],
                    'total_price_gross' => $itemData['total_price_gross'],
                    'currency' => $itemData['currency'],
                    'vat_rate' => $productValues['vat_rate'],
                    'weight' => $linkedProduct?->isBundle()
                        ? ($linkedProduct->package_weight_kg ?? $itemData['weight'])
                        : $itemData['weight'],
                ]);

                if ($linkedProduct !== null) {
                    $this->bundleSnapshots->capture($orderItem, $linkedProduct);
                }

                if ($linkedProduct !== null) {
                    $linkedProductCount++;
                }

                $link->itemLinks()->create([
                    'order_item_id' => $orderItem->getKey(),
                    'external_order_detail_id' => $itemData['external_order_detail_id'],
                    'external_product_id' => $itemData['external_product_id'],
                    'external_product_attribute_id' => $itemData['external_product_attribute_id'],
                ]);
            }

            foreach ($preview['payments'] as $paymentData) {
                $link->payments()->create($paymentData + [
                    'accounting_status' => 'accounted',
                    'accounted_at' => now(),
                ]);
            }

            $this->paymentStatuses->observe(
                $link,
                $preview['external_payment_paid'],
                $preview['total_gross'],
                $preview['currency'],
                $preview['native_paid_amount'],
            );

            $order->events()->create([
                'event_type' => 'prestashop_order_imported',
                'title' => 'Zaimportowano zamówienie z PrestaShop',
                'description' => ($preview['external_status_id'] === null
                    ? sprintf(
                        'Zamówienie %s nie miało przypisanego statusu w PrestaShop. W OMS ustawiono status Nowe.',
                        $preview['reference'],
                    )
                    : sprintf(
                        'Zamówienie %s, status PrestaShop: %s.',
                        $preview['reference'],
                        $preview['external_status_name'],
                    )).(collect($vatSources)->contains(fn (array $vat): bool => $vat['source'] === 'default') ? ' Zastosowano domyślną stawkę VAT integracji.' : ''),
                'payload' => [
                    'prestashop_account_id' => $lockedAccount->getKey(),
                    'external_order_id' => $preview['external_order_id'],
                    'external_reference' => $preview['reference'],
                    'external_order_state_id' => $preview['external_status_id'],
                    'source_hash' => $preview['source_hash'],
                    'linked_product_count' => $linkedProductCount,
                    'vat_sources' => $vatSources,
                ],
            ]);

            $this->buyerMessages->enqueue($link->id);

            return $order->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $preview */
    private function completeExistingOrder(
        Order $order,
        PrestashopOrderLink $orderLink,
        PrestashopAccount $account,
        array $preview,
    ): Order {
        $order = $this->completeMissingMetadata($order, $preview);

        return $this->completeMissingProductLinks($order, $orderLink, $account);
    }

    /** @param array<string, mixed> $preview */
    private function completeMissingMetadata(Order $order, array $preview): Order
    {
        $updates = [];

        if ($order->customer_login !== null) {
            $updates['customer_login'] = null;
        }

        if (blank($order->customer_phone) && filled($preview['customer_phone'])) {
            $updates['customer_phone'] = $preview['customer_phone'];
        }

        $pickupFields = [
            'pickup_point_name' => 'name',
            'pickup_point_id' => 'id',
            'pickup_point_address' => 'address',
            'pickup_point_postal_code' => 'postal_code',
            'pickup_point_city' => 'city',
        ];

        foreach ($pickupFields as $orderField => $previewField) {
            if (blank($order->{$orderField}) && filled($preview['pickup_point'][$previewField] ?? null)) {
                $updates[$orderField] = $preview['pickup_point'][$previewField];
            }
        }

        if ($updates === []) {
            return $order;
        }

        $order->forceFill($updates)->save();
        $order->events()->create([
            'event_type' => 'prestashop_order_metadata_completed',
            'title' => 'Uzupełniono dane zamówienia z PrestaShop',
            'description' => 'Uzupełniono brakujące dane kontaktowe lub punkt odbioru.',
            'payload' => [
                'updated_fields' => array_keys($updates),
            ],
        ]);

        return $order->refresh();
    }

    private function completeMissingProductLinks(
        Order $order,
        PrestashopOrderLink $orderLink,
        PrestashopAccount $account,
    ): Order {
        if (! $account->use_linked_products
            || $order->invoices()->exists()
            || $order->documentSlots()->exists()) {
            return $order;
        }

        $itemLinks = $orderLink->itemLinks()
            ->with('orderItem')
            ->get();
        $linkedProducts = $this->linkedProductsByExternalId(
            $account,
            $itemLinks->pluck('external_product_id')->all(),
        );
        $updatedItemIds = [];

        foreach ($itemLinks as $itemLink) {
            $orderItem = $itemLink->orderItem;
            $externalProductId = $itemLink->external_product_id;
            $linkedProduct = $externalProductId === null
                ? null
                : ($linkedProducts[$externalProductId] ?? null);

            if ($orderItem === null || $orderItem->product_id !== null || $linkedProduct === null) {
                continue;
            }

            $productValues = $this->productValues($account, [
                'product_name' => $orderItem->product_name,
                'vat_rate' => $orderItem->vat_rate,
            ], $linkedProduct, $order->shipping_country_code === 'PL' && $order->billing_country_code === 'PL', false);

            $orderItem->forceFill($productValues['values'])->save();
            $this->bundleSnapshots->capture($orderItem, $linkedProduct);
            $updatedItemIds[] = $orderItem->getKey();
        }

        if ($updatedItemIds === []) {
            return $order;
        }

        $order->events()->create([
            'event_type' => 'prestashop_order_products_linked',
            'title' => 'Powiązano produkty PrestaShop z katalogiem OMS',
            'description' => sprintf(
                'Uzupełniono powiązanie dla %d pozycji zamówienia.',
                count($updatedItemIds),
            ),
            'payload' => [
                'order_item_ids' => $updatedItemIds,
            ],
        ]);

        return $order->refresh();
    }

    /**
     * @param  array<int, mixed>  $externalProductIds
     * @return array<int, Product>
     */
    private function linkedProductsByExternalId(
        PrestashopAccount $account,
        array $externalProductIds,
    ): array {
        if (! $account->use_linked_products) {
            return [];
        }

        $ids = collect($externalProductIds)
            ->filter(fn (mixed $id): bool => filter_var(
                $id,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            ) !== false)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return PrestashopProductLink::query()
            ->where('prestashop_account_id', $account->getKey())
            ->whereIn('external_product_id', $ids)
            ->with('product')
            ->lockForUpdate()
            ->get()
            ->filter(fn (PrestashopProductLink $link): bool => $link->product !== null)
            ->mapWithKeys(fn (PrestashopProductLink $link): array => [
                $link->external_product_id => $link->product,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $sourceItem
     * @return array{values: array, vat_resolution: array}
     */
    private function productValues(
        PrestashopAccount $account,
        array $sourceItem,
        ?Product $linkedProduct,
        bool $domestic,
        bool $allowDefault = true,
    ): array {
        $vatMode = $account->catalog_vat_mode ?? PrestashopCatalogVatMode::Fallback;
        $resolution = $this->vat->resolve(
            $this->vat->source($sourceItem['vat_rate']), $linkedProduct?->vat_rate,
            $vatMode !== PrestashopCatalogVatMode::Store,
            $vatMode === PrestashopCatalogVatMode::Catalog,
            $domestic, $allowDefault ? $account->default_vat_rate : null,
        );

        return [
            'values' => [
                'product_id' => $linkedProduct?->getKey(),
                'product_name' => $linkedProduct !== null && $account->use_catalog_product_names
                    ? $linkedProduct->name : (string) $sourceItem['product_name'],
                'vat_rate' => $resolution['rate'],
            ],
            'vat_resolution' => $resolution,
        ];
    }

    /** @return array<string, mixed> */
    private function addressData(string $prefix, array $address): array
    {
        $fields = [
            'name',
            'company_name',
            'street',
            'building_number',
            'apartment_number',
            'postal_code',
            'city',
            'province',
            'country_code',
            'phone',
            'email',
        ];
        $data = [];

        foreach ($fields as $field) {
            $data[$prefix.'_'.$field] = $address[$field] ?? null;
        }

        if ($prefix === 'billing') {
            $data['billing_tax_id'] = $address['tax_id'] ?? null;
        }

        return $data;
    }
}
