<?php

namespace Modules\CustomerPortal\Models;

use App\Models\OrderStatusSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalStatus extends Model
{
    protected $table = 'customer_portal_statuses';

    protected $guarded = ['id'];

    protected $casts = ['is_visible' => 'boolean'];

    public function statusSetting(): BelongsTo
    {
        return $this->belongsTo(OrderStatusSetting::class, 'order_status_setting_id');
    }
}
