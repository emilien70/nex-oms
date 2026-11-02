<?php

namespace Modules\Integrations\VonHalsky\Services;

use DomainException;
use Modules\Integrations\Services\OrderImportVatResolver;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyProductLink;
use Modules\Products\Models\Product;
use Modules\Products\Services\OrderItemBundleSnapshotService;

class VonHalskyOrderCatalogService
{
    public function __construct(private readonly OrderImportVatResolver $vat,
        private readonly OrderItemBundleSnapshotService $bundles) {}

    public function apply(VonHalskyAccount $account, array $preview, bool $lock = false): array
    {
        $settings = ['use_linked_products' => (bool) $account->use_linked_products,
            'use_catalog_product_names' => (bool) $account->use_catalog_product_names,
            'use_catalog_vat' => (bool) $account->use_catalog_vat, 'default_vat_rate' => $account->default_vat_rate];
        $links = $settings['use_linked_products']
            ? VonHalskyProductLink::query()->where('von_halsky_account_id', $account->id)
                ->where('environment', $account->environment->value)->where('organization_id', $account->organization_id)
                ->whereIn('external_offer_id', collect($preview['items'])->pluck('offer_id')->filter()->unique())
                ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('external_offer_id')
            : collect();
        $products = Product::query()->whereIn('id', $links->pluck('product_id'))->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $domestic = $preview['attributes']['shipping_country_code'] === 'PL'
            && $preview['attributes']['billing_country_code'] === 'PL';
        $warnings = [];
        $blockers = [];

        foreach ($preview['items'] as &$item) {
            $item['source_product_name'] ??= $item['product_name'];
            $item['source_vat'] ??= $this->vat->source(null);
            $item['product_name'] = $item['source_product_name'];
            $item['product_id'] = null;
            $item['weight'] = null;
            $item['catalog'] = null;
            $link = $item['offer_id'] === null ? null : $links->get($item['offer_id']);
            $product = $link === null ? null : $products->get($link->product_id);
            $resolution = $this->vat->resolve($item['source_vat'], $product?->vat_rate,
                $settings['use_catalog_vat'], true, $domestic, $settings['default_vat_rate']);
            $item['vat_resolution'] = $resolution;
            $item['vat_rate'] = $resolution['rate'];
            $item['vat_source'] = $resolution['source'];
            if ($resolution['catalog_invalid']) {
                $warnings[] = 'Produkt OMS #'.$product->id.' nie ma poprawnej stawki VAT.';
            }
            if (in_array($item['source_vat']['status'], ['invalid', 'special'], true)) {
                $warnings[] = 'VAT pozycji wymaga recznej weryfikacji; nie zastosowano stawki domyslnej.';
            }
            if ($product === null) {
                if ($settings['use_linked_products']) {
                    $warnings[] = 'Brak powiazania z OMS dla pozycji '.($item['offer_id'] ?? $item['external_id']).'.';
                }

                continue;
            }
            $components = [];
            if ($product->isBundle()) {
                try {
                    $components = $this->bundles->components($product, $lock);
                } catch (DomainException $exception) {
                    $blockers[] = 'Zestaw OMS #'.$product->id.': '.$exception->getMessage();
                }
                $item['weight'] = $product->package_weight_kg;
            }
            $item['product_id'] = $product->id;
            $item['catalog'] = ['link_id' => $link->id, 'name' => $product->name, 'item_type' => $product->item_type,
                'vat_rate' => $product->vat_rate, 'bundle_components' => $components,
                'package_weight_kg' => $product->isBundle() ? $product->package_weight_kg : null];
            if ($settings['use_catalog_product_names']) {
                $item['product_name'] = $product->name;
            }
        }
        unset($item);

        $preview['catalog_warnings'] = array_values(array_unique($warnings));
        $preview['catalog_blockers'] = array_values(array_unique($blockers));
        $preview['catalog_hash'] = hash('sha256', json_encode(['settings' => $settings,
            'items' => array_map(fn (array $item): array => [
                'external_id' => $item['external_id'], 'product_id' => $item['product_id'],
                'product_name' => $item['product_name'], 'vat_rate' => $item['vat_rate'],
                'source_vat' => $item['source_vat'], 'vat_resolution' => $item['vat_resolution'],
                'catalog' => $item['catalog'], 'weight' => $item['weight'],
            ], $preview['items'])], JSON_THROW_ON_ERROR));

        return $preview;
    }
}
