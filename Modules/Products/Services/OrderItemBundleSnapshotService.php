<?php

namespace Modules\Products\Services;

use App\Models\OrderItem;
use DomainException;
use Modules\Products\Models\Product;

class OrderItemBundleSnapshotService
{
    public function capture(OrderItem $orderItem, Product $product): void
    {
        if (! $product->isBundle() || $orderItem->bundleComponents()->exists()) {
            return;
        }

        foreach ($this->components($product) as $component) {
            $orderItem->bundleComponents()->create($component);
        }
    }

    public function components(Product $product, bool $lock = false): array
    {
        $components = $product->bundleComponents()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();

        if ($components->count() < 2) {
            throw new DomainException('Zestaw nie posiada co najmniej dwóch prawidłowych składników.');
        }

        $products = Product::query()->whereIn('id', $components->pluck('component_product_id'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $snapshot = [];
        foreach ($components as $bundleComponent) {
            $component = $products->get($bundleComponent->component_product_id);

            if ($component === null || ! $component->isProduct() || $bundleComponent->quantity < 1) {
                throw new DomainException('Skład zestawu zawiera niedostępny produkt.');
            }

            $snapshot[] = [
                'bundle_product_id' => $product->getKey(),
                'component_product_id' => $component->getKey(),
                'component_product_name' => $component->name,
                'component_ean' => $component->ean,
                'quantity_per_bundle' => $bundleComponent->quantity,
            ];
        }

        return $snapshot;
    }
}
