<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderSavedView extends Model
{
    protected $fillable = [
        'name',
        'filters',
        'sort_order',
    ];

    protected $casts = [
        'filters' => 'array',
        'sort_order' => 'integer',
    ];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
