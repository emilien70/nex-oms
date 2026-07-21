<?php

namespace Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationAction extends Model
{
    protected $fillable = [
        'automation_rule_id',
        'action_type',
        'configuration',
        'stop_on_error',
        'sort_order',
    ];

    protected $casts = [
        'configuration' => 'array',
        'stop_on_error' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
