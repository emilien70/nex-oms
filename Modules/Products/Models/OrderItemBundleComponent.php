<?php

namespace Modules\Products\Models;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemBundleComponent extends Model
{
    protected $fillable = [
        'order_item_id',
        'bundle_product_id',
        'component_product_id',
        'component_product_name',
        'component_ean',
        'quantity_per_bundle',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_bundle' => 'integer',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
