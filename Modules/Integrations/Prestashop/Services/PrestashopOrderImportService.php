<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;

class PrestashopOrderImportService
{
    public function __construct(
        private readonly PrestashopAccountService $accountService,
        private readonly PrestashopOrderPreviewService $previewService,
    ) {}

    public function import(string $identifier): Order
    {
        $account = $this->accountService->get();

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

                return $this->completeMissingMetadata($existingOrder, $preview);
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

            foreach ($preview['items'] as $itemData) {
                $orderItem = $order->items()->create([
                    'product_id' => null,
                    'external_id' => (string) $itemData['external_order_detail_id'],
                    'product_name' => $itemData['product_name'],
                    'sku' => $itemData['sku'],
                    'ean' => $itemData['ean'],
                    'offer_id' => null,
                    'quantity' => $itemData['quantity'],
                    'unit_price_gross' => $itemData['unit_price_gross'],
                    'total_price_gross' => $itemData['total_price_gross'],
                    'currency' => $itemData['currency'],
                    'vat_rate' => $itemData['vat_rate'],
                    'weight' => $itemData['weight'],
                ]);

                $link->itemLinks()->create([
                    'order_item_id' => $orderItem->getKey(),
                    'external_order_detail_id' => $itemData['external_order_detail_id'],
                    'external_product_id' => $itemData['external_product_id'],
                    'external_product_attribute_id' => $itemData['external_product_attribute_id'],
                ]);
            }

            foreach ($preview['payments'] as $paymentData) {
                $link->payments()->create($paymentData);
            }

            $order->events()->create([
                'event_type' => 'prestashop_order_imported',
                'title' => 'Zaimportowano zamówienie z PrestaShop',
                'description' => sprintf(
                    'Zamówienie %s, status PrestaShop: %s.',
                    $preview['reference'],
                    $preview['external_status_name'],
                ),
                'payload' => [
                    'prestashop_account_id' => $lockedAccount->getKey(),
                    'external_order_id' => $preview['external_order_id'],
                    'external_reference' => $preview['reference'],
                    'external_order_state_id' => $preview['external_status_id'],
                    'source_hash' => $preview['source_hash'],
                ],
            ]);

            return $order->refresh();
        }, 3);
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
