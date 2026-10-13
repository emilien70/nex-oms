<?php

namespace Modules\Integrations\Prestashop\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrestashopOrderLink extends Model
{
    protected $table = 'prestashop_order_links';

    protected $fillable = [
        'prestashop_account_id',
        'order_id',
        'external_order_id',
        'external_reference',
        'external_order_state_id',
        'external_order_state_name',
        'external_created_at',
        'external_updated_at',
        'source_hash',
        'imported_at',
    ];

    protected $casts = [
        'external_order_id' => 'integer',
        'external_order_state_id' => 'integer',
        'external_created_at' => 'immutable_datetime',
        'external_updated_at' => 'immutable_datetime',
        'imported_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PrestashopAccount::class, 'prestashop_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function itemLinks(): HasMany
    {
        return $this->hasMany(PrestashopOrderItemLink::class, 'prestashop_order_link_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PrestashopOrderPayment::class, 'prestashop_order_link_id');
    }
}
