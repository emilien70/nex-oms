<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;

class OrderReturnRefundAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['products_value' => 'decimal:2', 'delivery_value' => 'decimal:2'];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(AllegroPaymentRefund::class, 'allegro_payment_refund_id');
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }
}
