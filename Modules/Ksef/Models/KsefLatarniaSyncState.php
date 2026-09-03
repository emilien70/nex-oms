<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaStatus;

class KsefLatarniaSyncState extends Model
{
    protected $fillable = [
        'source_environment',
        'current_status',
        'status_payload_json',
        'status_payload_hash',
        'status_last_attempt_at',
        'status_last_success_at',
        'status_last_error_at',
        'status_last_error_code',
        'status_last_error_message',
        'messages_last_attempt_at',
        'messages_last_success_at',
        'messages_last_error_at',
        'messages_last_error_code',
        'messages_last_error_message',
    ];

    protected $casts = [
        'source_environment' => KsefLatarniaEnvironment::class,
        'current_status' => KsefLatarniaStatus::class,
        'status_last_attempt_at' => 'immutable_datetime',
        'status_last_success_at' => 'immutable_datetime',
        'status_last_error_at' => 'immutable_datetime',
        'messages_last_attempt_at' => 'immutable_datetime',
        'messages_last_success_at' => 'immutable_datetime',
        'messages_last_error_at' => 'immutable_datetime',
    ];
}
