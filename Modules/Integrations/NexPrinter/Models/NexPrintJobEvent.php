<?php

namespace Modules\Integrations\NexPrinter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Integrations\NexPrinter\Enums\NexPrintJobStatus;

class NexPrintJobEvent extends Model
{
    protected $fillable = [
        'print_job_id',
        'event_type',
        'status',
        'actor',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'status' => NexPrintJobStatus::class,
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function printJob(): BelongsTo
    {
        return $this->belongsTo(NexPrintJob::class, 'print_job_id');
    }
}
