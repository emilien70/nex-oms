<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineCertificateKeyType;
use Modules\Ksef\Enums\KsefOfflineCertificateRemoteStatus;

class KsefOfflineCertificate extends Model
{
    protected $fillable = [
        'environment',
        'certificate_serial_number',
        'label',
        'certificate_pem',
        'private_key_pem',
        'valid_from',
        'valid_until',
        'fingerprint_sha256',
        'key_type',
        'key_size',
        'curve',
        'remote_status',
        'remote_certificate_name',
        'remote_valid_from',
        'remote_valid_until',
        'remote_verified_at',
    ];

    protected $hidden = [
        'certificate_pem',
        'private_key_pem',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'certificate_pem' => 'encrypted',
        'private_key_pem' => 'encrypted',
        'valid_from' => 'immutable_datetime',
        'valid_until' => 'immutable_datetime',
        'remote_valid_from' => 'immutable_datetime',
        'remote_valid_until' => 'immutable_datetime',
        'remote_verified_at' => 'immutable_datetime',
        'key_type' => KsefOfflineCertificateKeyType::class,
        'key_size' => 'integer',
    ];

    public function preferredSelection(): HasOne
    {
        return $this->hasOne(KsefOfflineCertificateSelection::class, 'offline_certificate_id');
    }

    public function fingerprintForDisplay(): string
    {
        return implode(':', str_split($this->fingerprint_sha256, 2));
    }

    public function remoteStatusLabel(): string
    {
        if ($this->remote_status === null) {
            return 'Niezweryfikowany';
        }

        return KsefOfflineCertificateRemoteStatus::tryFrom($this->remote_status)?->label()
            ?? 'Nieznany status KSeF';
    }
}
