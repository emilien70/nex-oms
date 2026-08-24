<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KsefInvoiceUpo extends Model
{
    protected $fillable = [
        'ksef_invoice_submission_id',
        'schema_id',
        'payload_xml',
        'payload_hash',
        'payload_size',
        'fetched_at',
    ];

    protected $hidden = [
        'payload_xml',
    ];

    protected $casts = [
        'payload_xml' => 'encrypted',
        'payload_size' => 'integer',
        'fetched_at' => 'immutable_datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(KsefInvoiceSubmission::class, 'ksef_invoice_submission_id');
    }
}
