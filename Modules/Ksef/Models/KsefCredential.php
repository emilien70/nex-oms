<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefConnectionTestStatus;
use Modules\Ksef\Enums\KsefEnvironment;

class KsefCredential extends Model
{
    protected $fillable = [
        'environment',
        'authentication_method',
        'api_token',
        'authentication_certificate',
        'authentication_private_key',
        'access_token',
        'access_token_valid_until',
        'refresh_token',
        'refresh_token_valid_until',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_test_invoice_write',
        'last_system_warning',
    ];

    protected $hidden = [
        'api_token',
        'authentication_certificate',
        'authentication_private_key',
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'authentication_method' => KsefAuthenticationMethod::class,
        'api_token' => 'encrypted',
        'authentication_certificate' => 'encrypted',
        'authentication_private_key' => 'encrypted',
        'access_token' => 'encrypted',
        'access_token_valid_until' => 'immutable_datetime',
        'refresh_token' => 'encrypted',
        'refresh_token_valid_until' => 'immutable_datetime',
        'last_tested_at' => 'immutable_datetime',
        'last_test_status' => KsefConnectionTestStatus::class,
        'last_test_invoice_write' => 'boolean',
    ];
}
