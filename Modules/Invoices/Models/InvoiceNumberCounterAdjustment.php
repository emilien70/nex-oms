<?php

namespace Modules\Invoices\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceNumberCounterAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'invoice_number_counter_id',
        'numbering_period_key_snapshot',
        'series_name_snapshot',
        'number_format_snapshot',
        'previous_last_sequence_number',
        'new_last_sequence_number',
        'previous_protected_floor_sequence_number',
        'new_protected_floor_sequence_number',
        'previous_next_sequence_number',
        'new_next_sequence_number',
        'reason',
        'actor_snapshot',
        'metadata',
    ];

    protected $casts = [
        'previous_last_sequence_number' => 'integer',
        'new_last_sequence_number' => 'integer',
        'previous_protected_floor_sequence_number' => 'integer',
        'new_protected_floor_sequence_number' => 'integer',
        'previous_next_sequence_number' => 'integer',
        'new_next_sequence_number' => 'integer',
        'actor_snapshot' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Historia ręcznych zmian licznika jest niezmienna.');
        });

        static::deleting(function (): never {
            throw new DomainException('Historia ręcznych zmian licznika jest niezmienna.');
        });
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(InvoiceNumberCounter::class, 'invoice_number_counter_id');
    }
}
