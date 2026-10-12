<?php

namespace Modules\Integrations\NexPrinter\Models;

use Illuminate\Database\Eloquent\Model;

class NexPrinterPairingCode extends Model
{
    protected $fillable = [
        'code_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
