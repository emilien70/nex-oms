<?php

namespace Modules\Integrations\Allegro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllegroOrderPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'immutable_datetime',
        'accounted_at' => 'immutable_datetime',
    ];

    public function orderLink(): BelongsTo
    {
        return $this->belongsTo(AllegroOrderLink::class, 'allegro_order_link_id');
    }
}
