<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;

class KsefInvoiceSubmission extends Model
{
    protected $fillable = [
        'invoice_id',
        'environment',
        'context_nip',
        'seller_nip',
        'attempt_number',
        'status',
        'schema_id',
        'generated_at',
        'payload_xml',
        'invoice_hash',
        'invoice_size',
        'public_key_id',
        'session_reference_number',
        'session_valid_until',
        'encrypted_invoice_hash',
        'encrypted_invoice_size',
        'invoice_reference_number',
        'session_closed_at',
        'ksef_status_code',
        'ksef_number',
        'acquisition_date',
        'invoicing_date',
        'permanent_storage_date',
        'last_checked_at',
        'next_follow_up_at',
        'follow_up_attempts',
        'follow_up_action',
        'last_follow_up_at',
        'last_follow_up_error_code',
        'last_follow_up_error_message',
        'safe_error_code',
        'safe_error_message',
        'session_close_error_code',
        'session_close_error_message',
    ];

    protected $hidden = [
        'payload_xml',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'attempt_number' => 'integer',
        'status' => KsefInvoiceSubmissionStatus::class,
        'generated_at' => 'immutable_datetime',
        'payload_xml' => 'encrypted',
        'invoice_size' => 'integer',
        'session_valid_until' => 'immutable_datetime',
        'encrypted_invoice_size' => 'integer',
        'session_closed_at' => 'immutable_datetime',
        'ksef_status_code' => 'integer',
        'acquisition_date' => 'immutable_datetime',
        'invoicing_date' => 'immutable_datetime',
        'permanent_storage_date' => 'immutable_datetime',
        'last_checked_at' => 'immutable_datetime',
        'next_follow_up_at' => 'immutable_datetime',
        'follow_up_attempts' => 'integer',
        'last_follow_up_at' => 'immutable_datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function upo(): HasOne
    {
        return $this->hasOne(KsefInvoiceUpo::class);
    }
}
