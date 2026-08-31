<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;

class KsefInvoiceProvenance extends Model
{
    protected $fillable = [
        'invoice_id',
        'environment',
        'provenance',
        'recorded_at',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'provenance' => KsefInvoiceProvenanceType::class,
        'recorded_at' => 'immutable_datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
