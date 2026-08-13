<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Models\InvoiceSeries;

class KsefSeriesSetting extends Model
{
    protected $fillable = [
        'invoice_series_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function invoiceSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class, 'invoice_series_id');
    }
}
