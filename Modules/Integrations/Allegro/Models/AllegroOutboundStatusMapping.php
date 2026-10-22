<?php

namespace Modules\Integrations\Allegro\Models;

use App\Models\OrderStatusSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;

class AllegroOutboundStatusMapping extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['allegro_status' => AllegroFulfillmentStatus::class];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function orderStatusSetting(): BelongsTo
    {
        return $this->belongsTo(OrderStatusSetting::class);
    }
}
