<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;

class KsefCredential extends Model
{
    protected $fillable = [
        'environment',
        'authentication_method',
        'api_token',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'authentication_method' => KsefAuthenticationMethod::class,
        'api_token' => 'encrypted',
    ];
}
