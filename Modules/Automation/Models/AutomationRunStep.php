<?php

namespace Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunStep extends Model
{
    protected $fillable = [
        'automation_run_id',
        'position',
        'action_type',
        'status',
        'configuration',
        'output',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'configuration' => 'array',
        'output' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
