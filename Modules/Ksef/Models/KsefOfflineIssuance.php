<?php

namespace Modules\Ksef\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Casts\KsefUtcInstantCast;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefEnvironment;
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
    ];

    protected $hidden = [
        'payload_xml',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'procedure' => KsefOfflineIssuanceProcedure::class,
        'issue_date' => 'immutable_date',
        'issued_at' => KsefUtcInstantCast::class,
        'context_identifier_type' => KsefContextIdentifierType::class,
        'payload_xml' => 'encrypted',
        'invoice_size' => 'integer',
        'certificate_valid_from' => 'immutable_datetime',
        'certificate_valid_until' => 'immutable_datetime',
        'certificate_remote_valid_from' => 'immutable_datetime',
        'certificate_remote_valid_until' => 'immutable_datetime',
        'certificate_remote_verified_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('Fakt wystawienia Offline24 jest niezmienny.');
        });

        static::deleting(function (): never {
            throw new DomainException('Fakt wystawienia Offline24 jest niezmienny.');
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
}
