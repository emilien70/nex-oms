<?php

namespace Modules\Integrations\Allegro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllegroOrderImportEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }
}
