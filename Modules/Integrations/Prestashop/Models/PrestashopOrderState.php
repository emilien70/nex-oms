<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOrderState extends Model
{
    protected $table = 'prestashop_order_states';

    protected $fillable = [
        'prestashop_account_id',
        'external_id',
        'name',
        'color',
        'unremovable',
        'delivery',
        'hidden',
        'send_email',
        'invoice',
        'logable',
        'shipped',
        'paid',
        'deleted',
        'is_available',
        'refreshed_at',
    ];

    protected $casts = [
        'external_id' => 'integer',
        'unremovable' => 'boolean',
        'delivery' => 'boolean',
        'hidden' => 'boolean',
        'send_email' => 'boolean',
        'invoice' => 'boolean',
        'logable' => 'boolean',
        'shipped' => 'boolean',
        'paid' => 'boolean',
        'deleted' => 'boolean',
        'is_available' => 'boolean',
        'refreshed_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PrestashopAccount::class, 'prestashop_account_id');
    }
}
