<?php

namespace Modules\Integrations\Prestashop\Models;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOrderItemLink extends Model
{
    protected $table = 'prestashop_order_item_links';

    protected $fillable = [
        'prestashop_order_link_id',
        'order_item_id',
        'external_order_detail_id',
        'external_product_id',
        'external_product_attribute_id',
    ];

    protected $casts = [
        'external_order_detail_id' => 'integer',
        'external_product_id' => 'integer',
        'external_product_attribute_id' => 'integer',
    ];

    public function orderLink(): BelongsTo
    {
        return $this->belongsTo(PrestashopOrderLink::class, 'prestashop_order_link_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
