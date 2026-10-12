<?php

namespace Modules\Integrations\NexPrinter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexPrinterAgent extends Model
{
    protected $fillable = [
        'public_id',
        'name',
        'platform',
        'client_version',
        'token_hash',
        'is_active',
        'paired_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'paired_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function printers(): HasMany
    {
        return $this->hasMany(NexPrinter::class, 'agent_id');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(NexPrintJob::class, 'agent_id');
    }
}
