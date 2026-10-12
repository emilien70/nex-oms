<?php

namespace Modules\Integrations\NexPrinter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexPrinter extends Model
{
    protected $fillable = [
        'agent_id',
        'system_name',
        'display_name',
        'is_default',
        'is_enabled',
        'is_available',
        'capabilities',
        'last_seen_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_enabled' => 'boolean',
        'is_available' => 'boolean',
        'capabilities' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(NexPrinterAgent::class, 'agent_id');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(NexPrintJob::class, 'printer_id');
    }
}
