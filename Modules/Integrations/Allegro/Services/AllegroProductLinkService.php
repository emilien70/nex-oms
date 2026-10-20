<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroProductLink;
use Modules\Products\Models\Product;

class AllegroProductLinkService
{
    public function create(Product $product, AllegroAccount $account, string $offerId): AllegroProductLink
    {
        return $this->save($product, $account, $offerId);
    }

    public function update(Product $product, AllegroAccount $account, AllegroProductLink $link, string $offerId): AllegroProductLink
    {
        return $this->save($product, $account, $offerId, $link);
    }

    public function delete(Product $product, AllegroAccount $account, AllegroProductLink $link): void
    {
        DB::transaction(function () use ($product, $account, $link): void {
            $account = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
            $this->ownedLink($product, $account, $link)->delete();
        });
    }

    private function save(Product $product, AllegroAccount $account, string $offerId, ?AllegroProductLink $link = null): AllegroProductLink
    {
        if (preg_match('/^[1-9][0-9]{0,31}$/', $offerId) !== 1) {
            throw ValidationException::withMessages(['external_offer_id' => 'Wpisz prawidłowy numer oferty Allegro.']);
        }

        try {
            return DB::transaction(function () use ($product, $account, $offerId, $link): AllegroProductLink {
                $account = AllegroAccount::query()->lockForUpdate()->findOrFail($account->id);
                if (! $account->isOperational() || blank($account->allegro_user_id)) {
                    throw ValidationException::withMessages(['integration' => 'Połącz wybrane konto Allegro przed zapisaniem powiązania.']);
                }
                $owned = $link === null ? new AllegroProductLink : $this->ownedLink($product, $account, $link);
                $conflict = AllegroProductLink::query()->where('allegro_account_id', $account->id)
                    ->where('environment', $account->environment)->where('seller_user_id', $account->allegro_user_id)
                    ->where('external_offer_id', $offerId)->when($owned->exists, fn ($query) => $query->whereKeyNot($owned->id))->first();
                if ($conflict !== null) {
                    throw ValidationException::withMessages(['external_offer_id' => $conflict->product_id === $product->id
                        ? 'Ta oferta Allegro jest już powiązana z tą pozycją OMS.'
                        : 'Ta oferta Allegro jest już powiązana z inną pozycją OMS.']);
                }

                $owned->fill([
                    'product_id' => $product->id,
                    'allegro_account_id' => $account->id,
                    'environment' => $account->environment,
                    'seller_user_id' => $account->allegro_user_id,
                    'external_offer_id' => $offerId,
                ])->save();

                return $owned->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_offer_id' => 'Ta oferta Allegro ma już powiązanie w OMS.']);
        }
    }

    private function ownedLink(Product $product, AllegroAccount $account, AllegroProductLink $link): AllegroProductLink
    {
        return AllegroProductLink::query()->whereKey($link->id)->where('product_id', $product->id)
            ->where('allegro_account_id', $account->id)->where('environment', $account->environment)
            ->where('seller_user_id', $account->allegro_user_id)->lockForUpdate()->firstOrFail();
    }
}
