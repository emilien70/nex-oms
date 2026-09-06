<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Casts\KsefUtcInstantCast;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;

class KsefInvoiceSubmission extends Model
{
    protected $fillable = [
        'invoice_id',
        'offline_issuance_id',
        'offline_technical_correction_id',
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
        'invoicing_mode',
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
        'generated_at' => KsefUtcInstantCast::class,
        'payload_xml' => 'encrypted',
        'invoice_size' => 'integer',
        'session_valid_until' => KsefUtcInstantCast::class,
        'encrypted_invoice_size' => 'integer',
        'session_closed_at' => KsefUtcInstantCast::class,
        'ksef_status_code' => 'integer',
        'invoicing_mode' => KsefInvoicingMode::class,
        'acquisition_date' => KsefUtcInstantCast::class,
        'invoicing_date' => KsefUtcInstantCast::class,
        'permanent_storage_date' => KsefUtcInstantCast::class,
        'last_checked_at' => KsefUtcInstantCast::class,
        'next_follow_up_at' => KsefUtcInstantCast::class,
        'follow_up_attempts' => 'integer',
        'last_follow_up_at' => KsefUtcInstantCast::class,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function offlineIssuance(): BelongsTo
    {
        return $this->belongsTo(KsefOfflineIssuance::class, 'offline_issuance_id');
    }

    public function offlineTechnicalCorrection(): BelongsTo
    {
        return $this->belongsTo(KsefOfflineTechnicalCorrection::class, 'offline_technical_correction_id');
    }

    public function expectedInvoicingMode(): KsefInvoicingMode
    {
        return $this->offline_issuance_id === null
            ? KsefInvoicingMode::Online
            : KsefInvoicingMode::Offline;
    }

    public function hasExpectedInvoicingMode(?KsefInvoicingMode $actual = null): bool
    {
        $actual ??= $this->invoicing_mode;

        if ($actual === null) {
            return $this->offline_issuance_id === null;
        }

        return $actual === $this->expectedInvoicingMode();
    }

    public function upo(): HasOne
    {
        return $this->hasOne(KsefInvoiceUpo::class);
    }
}
