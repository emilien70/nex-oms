<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOrderMessage extends Model
{
    protected $fillable = [
        'prestashop_order_link_id',
        'external_message_id',
        'external_customer_id',
        'author_name',
        'message',
        'source_created_at',
    ];

    protected $casts = [
        'external_message_id' => 'integer',
        'external_customer_id' => 'integer',
        'source_created_at' => 'immutable_datetime',
    ];

    public function orderLink(): BelongsTo
    {
        return $this->belongsTo(PrestashopOrderLink::class, 'prestashop_order_link_id');
    }
}
