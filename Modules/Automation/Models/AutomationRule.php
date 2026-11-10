<?php

namespace Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'group_id',
        'trigger',
        'conditions',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'group_id' => 'integer',
        'conditions' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class)->orderBy('sort_order')->orderBy('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
