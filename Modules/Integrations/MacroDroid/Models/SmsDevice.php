<?php

namespace Modules\Integrations\MacroDroid\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SmsDevice extends Model
{
    protected $table = 'macrodroid_devices';

    protected $guarded = ['id'];

    protected $hidden = ['webhook_url', 'token_hash'];

    protected $casts = [
        'enabled' => 'boolean', 'webhook_url' => 'encrypted', 'generation' => 'integer',
        'lock_version' => 'integer', 'per_minute' => 'integer', 'per_day' => 'integer',
        'lifetime_minutes' => 'integer', 'max_parts' => 'integer',
        'last_seen_at' => 'immutable_datetime', 'probe_expires_at' => 'immutable_datetime',
        'probe_confirmed_at' => 'immutable_datetime',
    ];

    public static function configured(): ?self
    {
        return Schema::hasTable('macrodroid_devices') ? self::query()->where('singleton_key', 'primary')->first() : null;
    }
}
