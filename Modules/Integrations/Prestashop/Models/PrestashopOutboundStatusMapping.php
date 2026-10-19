<?php

namespace Modules\Integrations\Prestashop\Models;

use App\Models\OrderStatusSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOutboundStatusMapping extends Model
{
    protected $table = 'prestashop_outbound_status_mappings';

    protected $fillable = [
        'prestashop_account_id',
        'order_status_setting_id',
        'external_order_state_id',
    ];

    protected $casts = [
        'external_order_state_id' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PrestashopAccount::class, 'prestashop_account_id');
    }

    public function statusSetting(): BelongsTo
    {
        return $this->belongsTo(OrderStatusSetting::class, 'order_status_setting_id');
    }
}
