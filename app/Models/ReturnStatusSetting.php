<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnStatusSetting extends Model
{
    protected $fillable = ['code', 'name', 'color', 'sort_order'];

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class, 'local_status_id');
    }

    public static function initial(): self
    {
        return self::query()->where('code', 'new')->firstOrFail();
    }

    public function textColor(): string
    {
        $color = ltrim($this->color, '#');

        return (hexdec(substr($color, 0, 2)) * 299 + hexdec(substr($color, 2, 2)) * 587 + hexdec(substr($color, 4, 2)) * 114) > 160000 ? '#111827' : '#ffffff';
    }
}
