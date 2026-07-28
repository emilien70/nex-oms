<?php

namespace Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceNumberCounter extends Model
{
    protected $fillable = [
        'invoice_series_id',
        'numbering_period_key',
        'last_sequence_number',
        'protected_floor_sequence_number',
    ];

    protected $casts = [
        'last_sequence_number' => 'integer',
        'protected_floor_sequence_number' => 'integer',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class, 'invoice_series_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InvoiceNumberCounterAdjustment::class, 'invoice_number_counter_id');
    }

    public function nextSequenceNumber(): int
    {
        return $this->last_sequence_number + 1;
    }
}
