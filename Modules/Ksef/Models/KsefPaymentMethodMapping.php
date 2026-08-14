<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Ksef\Enums\KsefPaymentType;

class KsefPaymentMethodMapping extends Model
{
    protected $fillable = [
        'source_key',
        'source_label',
        'target_type',
    ];

    protected $casts = [
        'target_type' => KsefPaymentType::class,
    ];
}
