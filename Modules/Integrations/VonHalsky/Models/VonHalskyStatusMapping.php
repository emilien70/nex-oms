<?php

namespace Modules\Integrations\VonHalsky\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction;

class VonHalskyStatusMapping extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['action' => VonHalskyOrderAction::class];
}
