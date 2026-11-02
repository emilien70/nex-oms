<?php

namespace Modules\Integrations\VonHalsky\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderAction;

class VonHalskyOrderCommand extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['operation_key', 'sending_token'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'action' => VonHalskyOrderAction::class, 'lock_version' => 'integer',
        'configuration_revision' => 'integer', 'connection_revision' => 'integer',
        'sending_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime',
        'last_dispatched_at' => 'immutable_datetime', 'retried_at' => 'immutable_datetime',
    ];

    public function stateLabel(): string
    {
        return match ($this->state) {
            'queued' => 'Oczekiwanie na przekazanie',
            'sending' => 'Przekazywanie',
            'pending' => 'Oczekiwanie na wynik',
            'succeeded' => 'Potwierdzono',
            'failed' => 'Błąd',
            'blocked' => 'Zablokowano',
            'skipped' => 'Pominięto',
            default => 'Wymaga sprawdzenia',
        };
    }
}
