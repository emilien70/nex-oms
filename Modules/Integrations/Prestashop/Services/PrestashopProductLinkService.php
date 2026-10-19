<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopProductLink;
use Modules\Products\Models\Product;

class PrestashopProductLinkService
{
    public function create(
        Product $product,
        PrestashopAccount $account,
        int $externalProductId,
    ): PrestashopProductLink {
        return DB::transaction(function () use ($product, $account, $externalProductId): PrestashopProductLink {
            $this->ensureExternalProductIsAvailable($product, $account, $externalProductId);

            return PrestashopProductLink::query()->create([
                'product_id' => $product->getKey(),
                'prestashop_account_id' => $account->getKey(),
                'external_product_id' => $externalProductId,
                'external_product_attribute_id' => 0,
            ]);
        });
    }

    public function update(
        Product $product,
        PrestashopAccount $account,
        PrestashopProductLink $link,
        int $externalProductId,
    ): PrestashopProductLink {
        return DB::transaction(function () use (
            $product,
            $account,
            $link,
            $externalProductId,
        ): PrestashopProductLink {
            $lockedLink = $this->ownedLink($product, $account, $link, true);

            $this->ensureExternalProductIsAvailable(
                $product,
                $account,
                $externalProductId,
                $lockedLink,
            );

            $lockedLink->update([
                'external_product_id' => $externalProductId,
                'external_product_attribute_id' => 0,
            ]);

            return $lockedLink->refresh();
        });
    }

    public function delete(
        Product $product,
        PrestashopAccount $account,
        PrestashopProductLink $link,
    ): void {
        $this->ownedLink($product, $account, $link)->delete();
    }

    private function ensureExternalProductIsAvailable(
        Product $product,
        PrestashopAccount $account,
        int $externalProductId,
        ?PrestashopProductLink $ignoredLink = null,
    ): void {
        $conflictingLink = PrestashopProductLink::query()
            ->where('prestashop_account_id', $account->getKey())
            ->where('external_product_id', $externalProductId)
            ->where('external_product_attribute_id', 0)
            ->when(
                $ignoredLink !== null,
                fn ($query) => $query->whereKeyNot($ignoredLink->getKey()),
            )
            ->lockForUpdate()
            ->first();

        if ($conflictingLink === null) {
            return;
        }

        throw ValidationException::withMessages([
            'external_product_id' => $conflictingLink->product_id === $product->getKey()
                ? 'Ta oferta PrestaShop jest już powiązana z tą pozycją OMS.'
                : 'Ta oferta PrestaShop jest już powiązana z inną pozycją OMS.',
        ]);
    }

    private function ownedLink(
        Product $product,
        PrestashopAccount $account,
        PrestashopProductLink $link,
        bool $lock = false,
    ): PrestashopProductLink {
        return PrestashopProductLink::query()
            ->whereKey($link->getKey())
            ->where('product_id', $product->getKey())
            ->where('prestashop_account_id', $account->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }
}
