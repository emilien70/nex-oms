<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\Product;

class PrestashopProductLink extends Model
{
    protected $fillable = [
        'product_id',
        'prestashop_account_id',
        'external_product_id',
        'external_product_attribute_id',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'prestashop_account_id' => 'integer',
            'external_product_id' => 'integer',
            'external_product_attribute_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PrestashopAccount::class, 'prestashop_account_id');
    }
}
