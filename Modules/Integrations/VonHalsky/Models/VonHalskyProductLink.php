<?php

namespace Modules\Integrations\VonHalsky\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\Product;

class VonHalskyProductLink extends Model
{
    protected $fillable = ['product_id', 'von_halsky_account_id', 'environment', 'organization_id', 'external_offer_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VonHalskyAccount::class, 'von_halsky_account_id');
    }

    public function belongsToCurrentIdentity(): bool
    {
        return $this->account !== null
            && $this->environment === $this->account->environment->value
            && $this->organization_id === $this->account->organization_id;
    }
}
