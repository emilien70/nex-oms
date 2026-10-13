<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOrderPayment extends Model
{
    protected $table = 'prestashop_order_payments';

    protected $fillable = [
        'prestashop_order_link_id',
        'external_payment_id',
        'transaction_id',
        'amount',
        'currency',
        'method',
        'paid_at',
    ];

    protected $casts = [
        'external_payment_id' => 'integer',
        'amount' => 'decimal:2',
        'paid_at' => 'immutable_datetime',
    ];

    public function orderLink(): BelongsTo
    {
        return $this->belongsTo(PrestashopOrderLink::class, 'prestashop_order_link_id');
    }
}
