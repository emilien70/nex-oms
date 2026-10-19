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

        $components = $product->bundleComponents()
            ->with('component')
            ->orderBy('id')
            ->get();

        if ($components->count() < 2) {
            throw new DomainException('Zestaw nie posiada co najmniej dwóch prawidłowych składników.');
        }

        foreach ($components as $bundleComponent) {
            $component = $bundleComponent->component;

            if ($component === null || ! $component->isProduct()) {
                throw new DomainException('Skład zestawu zawiera niedostępny produkt.');
            }

            $orderItem->bundleComponents()->create([
                'bundle_product_id' => $product->getKey(),
                'component_product_id' => $component->getKey(),
                'component_product_name' => $component->name,
                'component_ean' => $component->ean,
                'quantity_per_bundle' => $bundleComponent->quantity,
            ]);
        }
    }
}
