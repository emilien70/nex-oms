<?php

namespace Modules\Automation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRuleGroup extends Model
{
    protected $fillable = ['name', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function scopeInDisplayOrder(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AutomationRule::class, 'group_id');
    }
}
