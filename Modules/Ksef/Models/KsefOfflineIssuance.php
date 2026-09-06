<?php

namespace Modules\Ksef\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Casts\KsefUtcInstantCast;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;

class KsefOfflineIssuance extends Model
{
    protected $fillable = [
        'invoice_id',
        'environment',
        'procedure',
        'issue_date',
        'issued_at',
        'seller_nip',
        'context_identifier_type',
        'context_identifier_value',
        'schema_id',
        'payload_xml',
        'correction_financial_evidence',
        'invoice_hash',
        'invoice_size',
        'offline_certificate_id',
        'certificate_serial_number',
        'certificate_fingerprint_sha256',
        'certificate_valid_from',
        'certificate_valid_until',
        'certificate_remote_status',
        'certificate_remote_valid_from',
        'certificate_remote_valid_until',
        'certificate_remote_verified_at',
        'invoice_verification_url',
        'certificate_verification_url',
        'latarnia_source_environment',
        'latarnia_trigger_event_id',
        'latarnia_trigger_message_id',
        'latarnia_trigger_message_version',
        'latarnia_trigger_category',
        'latarnia_trigger_start_at',
        'latarnia_trigger_end_at',
        'latarnia_trigger_published_at',
        'latarnia_evidence_as_of_at',
        'latarnia_evidence_from_at',
        'latarnia_evidence_through_at',
    ];

    protected $hidden = [
        'payload_xml',
        'correction_financial_evidence',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'procedure' => KsefOfflineIssuanceProcedure::class,
        'issue_date' => 'immutable_date',
        'issued_at' => KsefUtcInstantCast::class,
        'context_identifier_type' => KsefContextIdentifierType::class,
        'payload_xml' => 'encrypted',
        'correction_financial_evidence' => 'encrypted:array',
        'invoice_size' => 'integer',
        'certificate_valid_from' => 'immutable_datetime',
        'certificate_valid_until' => 'immutable_datetime',
        'certificate_remote_valid_from' => 'immutable_datetime',
        'certificate_remote_valid_until' => 'immutable_datetime',
        'certificate_remote_verified_at' => 'immutable_datetime',
        'latarnia_source_environment' => KsefLatarniaEnvironment::class,
        'latarnia_trigger_event_id' => 'integer',
        'latarnia_trigger_message_version' => 'integer',
        'latarnia_trigger_category' => KsefLatarniaMessageCategory::class,
        'latarnia_trigger_start_at' => KsefUtcInstantCast::class,
        'latarnia_trigger_end_at' => KsefUtcInstantCast::class,
        'latarnia_trigger_published_at' => KsefUtcInstantCast::class,
        'latarnia_evidence_as_of_at' => KsefUtcInstantCast::class,
        'latarnia_evidence_from_at' => KsefUtcInstantCast::class,
        'latarnia_evidence_through_at' => KsefUtcInstantCast::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Fakt wystawienia Offline jest niezmienny.');
        });

        static::deleting(function (): never {
            throw new DomainException('Fakt wystawienia Offline jest niezmienny.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function offlineCertificate(): BelongsTo
    {
        return $this->belongsTo(KsefOfflineCertificate::class, 'offline_certificate_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(KsefInvoiceSubmission::class, 'offline_issuance_id');
    }

    public function technicalCorrection(): HasOne
    {
        return $this->hasOne(KsefOfflineTechnicalCorrection::class, 'offline_issuance_id');
    }
}
