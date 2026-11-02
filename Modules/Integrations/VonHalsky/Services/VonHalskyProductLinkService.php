<?php

namespace Modules\Integrations\VonHalsky\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyProductLink;
use Modules\Products\Models\Product;

class VonHalskyProductLinkService
{
    public function save(Product $product, VonHalskyAccount $account, string $offerId, int $expectedRevision,
        ?VonHalskyProductLink $link = null): VonHalskyProductLink
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $offerId) !== 1) {
            throw ValidationException::withMessages(['external_offer_id' => 'Wpisz prawidlowe ID oferty Von Halsky.']);
        }

        try {
            return DB::transaction(function () use ($product, $account, $offerId, $expectedRevision, $link): VonHalskyProductLink {
                $account = $this->account($account, $expectedRevision);
                if (! $account->is_active || $account->last_test_status !== 'success' || blank($account->organization_id)) {
                    throw ValidationException::withMessages(['integration' => 'Polacz konto Von Halsky przed zapisaniem powiazania.']);
                }
                $owned = $link === null ? new VonHalskyProductLink : $this->ownedLink($product, $account, $link);
                $conflict = VonHalskyProductLink::query()->where('environment', $account->environment->value)
                    ->where('organization_id', $account->organization_id)->where('external_offer_id', $offerId)
                    ->when($owned->exists, fn ($query) => $query->whereKeyNot($owned->id))->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['external_offer_id' => 'Ta oferta Von Halsky ma juz powiazanie w OMS.']);
                }
                $owned->fill(['product_id' => $product->id, 'von_halsky_account_id' => $account->id,
                    'environment' => $account->environment->value, 'organization_id' => $account->organization_id,
                    'external_offer_id' => $offerId])->save();

                return $owned->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_offer_id' => 'Ta oferta Von Halsky ma juz powiazanie w OMS.']);
        }
    }

    public function delete(Product $product, VonHalskyAccount $account, VonHalskyProductLink $link, int $expectedRevision): void
    {
        DB::transaction(function () use ($product, $account, $link, $expectedRevision): void {
            $account = $this->account($account, $expectedRevision);
            $this->ownedLink($product, $account, $link)->delete();
        });
    }

    private function account(VonHalskyAccount $account, int $expectedRevision): VonHalskyAccount
    {
        $account = VonHalskyAccount::query()->lockForUpdate()->findOrFail($account->id);
        if ($account->connection_revision !== $expectedRevision) {
            throw ValidationException::withMessages(['integration' => 'Polaczenie zostalo zmienione. Odswiez liste produktow.']);
        }

        return $account;
    }

    private function ownedLink(Product $product, VonHalskyAccount $account, VonHalskyProductLink $link): VonHalskyProductLink
    {
        return VonHalskyProductLink::query()->whereKey($link->id)->where('product_id', $product->id)
            ->where('von_halsky_account_id', $account->id)->where('environment', $account->environment->value)
            ->where('organization_id', $account->organization_id)->lockForUpdate()->firstOrFail();
    }
}
