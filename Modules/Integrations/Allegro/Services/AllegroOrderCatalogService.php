<?php

namespace Modules\Integrations\Allegro\Services;

use DomainException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroProductLink;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use Modules\Products\Models\Product;
use Modules\Products\Services\OrderItemBundleSnapshotService;

class AllegroOrderCatalogService
{
    public function __construct(
        private readonly InvoiceFinancialValueValidator $financial,
        private readonly OrderItemBundleSnapshotService $bundleSnapshots,
    ) {}

    public function apply(AllegroAccount $account, array $preview, bool $lock = false): array
    {
        $settings = [
            'use_linked_products' => (bool) $account->use_linked_products,
            'use_catalog_product_names' => (bool) $account->use_catalog_product_names,
            'use_catalog_vat' => (bool) $account->use_catalog_vat,
        ];
        $ids = collect($preview['items'])->pluck('offer_id')->filter()->unique()->values();
        $links = $settings['use_linked_products']
            ? AllegroProductLink::query()->where('allegro_account_id', $account->id)
                ->where('environment', $account->environment)->where('seller_user_id', $account->allegro_user_id)
                ->whereIn('external_offer_id', $ids)->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('external_offer_id')
            : collect();
        $products = Product::query()->whereIn('id', $links->pluck('product_id'))->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $domestic = $preview['marketplace_id'] === 'allegro-pl'
            && $preview['shipping_address']['country_code'] === 'PL'
            && $preview['billing_address']['country_code'] === 'PL';
        $warnings = [];
        $blockers = [];

        foreach ($preview['items'] as &$item) {
            $item['source_product_name'] = $item['source_product_name'] ?? $item['product_name'];
            $item['product_name'] = $item['source_product_name'];
            $item['product_id'] = null;
            $item['vat_rate'] = null;
            $item['catalog'] = null;
            $link = $item['offer_id'] === null ? null : $links->get($item['offer_id']);
            $product = $link === null ? null : $products->get($link->product_id);
            if ($product === null) {
                if ($settings['use_linked_products'] && $item['offer_id'] !== null) {
                    $warnings[] = 'Brak powiązania z OMS dla oferty Allegro '.$item['offer_id'].'.';
                }

                continue;
            }

            $components = [];
            if ($product->isBundle()) {
                try {
                    $components = $this->bundleSnapshots->components($product, $lock);
                } catch (DomainException $exception) {
                    $blockers[] = 'Zestaw OMS #'.$product->id.': '.$exception->getMessage();
                }
            }
            $item['product_id'] = $product->id;
            $item['catalog'] = [
                'link_id' => $link->id,
                'name' => $product->name,
                'item_type' => $product->item_type,
                'thumbnail_path' => $product->thumbnail_path,
                'image_checksum' => $product->image_checksum,
                'vat_rate' => $product->vat_rate,
                'bundle_components' => $components,
            ];
            if ($settings['use_catalog_product_names']) {
                $item['product_name'] = $product->name;
            }
            if ($settings['use_catalog_vat'] && $domestic && $product->vat_rate !== null) {
                try {
                    $item['vat_rate'] = $this->financial->assertVatPercentage($product->vat_rate);
                } catch (InvoiceDomainException) {
                    $warnings[] = 'Produkt OMS #'.$product->id.' nie ma poprawnej stawki VAT. Sprawdź ją przed wystawieniem faktury.';
                }
            } elseif ($settings['use_catalog_vat'] && ! $domestic) {
                $warnings[] = 'Brak potwierdzonego krajowego kontekstu zamówienia: VAT pozostawiono do ręcznej weryfikacji.';
            }
        }
        unset($item);

        $preview['catalog_settings'] = $settings;
        $preview['catalog_warnings'] = array_values(array_unique($warnings));
        $preview['catalog_blockers'] = array_values(array_unique($blockers));
        $preview['catalog_hash'] = hash('sha256', json_encode([
            'settings' => $settings,
            'items' => array_map(fn (array $item): array => [
                'external_id' => $item['external_id'], 'product_id' => $item['product_id'],
                'product_name' => $item['product_name'], 'vat_rate' => $item['vat_rate'], 'catalog' => $item['catalog'],
            ], $preview['items']),
        ], JSON_THROW_ON_ERROR));

        return $preview;
    }
}
