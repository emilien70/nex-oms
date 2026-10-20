<?php

namespace Modules\Integrations\Allegro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Integrations\Allegro\Support\AllegroOfferUrl;
use Modules\Products\Models\Product;

class AllegroProductLink extends Model
{
    protected $fillable = ['product_id', 'allegro_account_id', 'environment', 'seller_user_id', 'external_offer_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function belongsToCurrentIdentity(): bool
    {
        return $this->account !== null && $this->environment === $this->account->environment
            && $this->seller_user_id === $this->account->allegro_user_id;
    }

    public function offerUrl(): ?string
    {
        return AllegroOfferUrl::make($this->environment, $this->external_offer_id);
    }
}
