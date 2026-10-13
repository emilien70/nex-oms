<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Integrations\Prestashop\Models\PrestashopOrderItemLink;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Products\Models\Product;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'external_id',
        'product_name',
        'sku',
        'ean',
        'offer_id',
        'quantity',
        'unit_price_gross',
        'total_price_gross',
        'currency',
        'vat_rate',
        'weight',
    ];

    protected $casts = [
        'unit_price_gross' => 'decimal:2',
        'total_price_gross' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'weight' => 'decimal:3',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prestashopItemLink(): HasOne
    {
        return $this->hasOne(PrestashopOrderItemLink::class, 'order_item_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
