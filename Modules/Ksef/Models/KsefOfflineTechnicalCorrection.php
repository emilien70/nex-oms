<?php

namespace Modules\Ksef\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Casts\KsefUtcInstantCast;
use Modules\Ksef\Enums\KsefEnvironment;

final class KsefOfflineTechnicalCorrection extends Model
{
    protected $fillable = [
        'invoice_id',
        'offline_issuance_id',
        'rejected_submission_id',
        'environment',
        'context_nip',
        'seller_nip',
        'schema_id',
        'generated_at',
        'payload_xml',
        'invoice_hash',
        'invoice_size',
        'hash_of_corrected_invoice',
        'source_status_code',
        'eligibility_policy_version',
        'business_fingerprint',
        'business_fingerprint_version',
    ];

    protected $hidden = [
        'payload_xml',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'generated_at' => KsefUtcInstantCast::class,
        'payload_xml' => 'encrypted',
        'invoice_size' => 'integer',
        'source_status_code' => 'integer',
        'eligibility_policy_version' => 'integer',
        'business_fingerprint_version' => 'integer',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Korekta techniczna KSeF jest niezmienna.');
        });

        self::deleting(function (): never {
            throw new DomainException('Korekta techniczna KSeF jest niezmienna.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function offlineIssuance(): BelongsTo
    {
        return $this->belongsTo(KsefOfflineIssuance::class, 'offline_issuance_id');
    }

    public function rejectedSubmission(): BelongsTo
    {
        return $this->belongsTo(KsefInvoiceSubmission::class, 'rejected_submission_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(KsefInvoiceSubmission::class, 'offline_technical_correction_id');
    }
}
