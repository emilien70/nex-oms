<?php

namespace Modules\Invoices\Models;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Enums\InvoiceItemType;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'order_item_id',
        'product_id',
        'source_invoice_item_id',
        'line_type',
        'position',
        'name',
        'description',
        'unit_name',
        'quantity',
        'unit_price_net',
        'unit_price_gross',
        'total_net',
        'total_vat',
        'total_gross',
        'vat_rate',
        'vat_code',
        'gtu_codes',
        'product_snapshot',
        'metadata',
        'correction_before_snapshot',
        'correction_after_snapshot',
        'correction_difference_snapshot',
    ];

    protected $casts = [
        'line_type' => InvoiceItemType::class,
        'position' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price_net' => 'decimal:4',
        'unit_price_gross' => 'decimal:4',
        'total_net' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_gross' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'gtu_codes' => 'array',
        'product_snapshot' => 'array',
        'metadata' => 'array',
        'correction_before_snapshot' => 'array',
        'correction_after_snapshot' => 'array',
        'correction_difference_snapshot' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function sourceInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_invoice_item_id');
    }

    public function correctionItems(): HasMany
    {
        return $this->hasMany(self::class, 'source_invoice_item_id');
    }
}
