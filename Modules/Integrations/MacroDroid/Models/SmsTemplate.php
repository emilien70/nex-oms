<?php

namespace Modules\Integrations\MacroDroid\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['lock_version' => 'integer'];
}
