<?php

namespace Modules\Invoices\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Enums\InvoiceDocumentType;

class OrderDocumentSlot extends Model
{
    protected $fillable = [
        'order_id',
        'document_type',
        'invoice_id',
    ];

    protected $casts = [
        'document_type' => InvoiceDocumentType::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
