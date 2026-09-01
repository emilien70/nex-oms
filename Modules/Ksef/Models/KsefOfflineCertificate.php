<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineCertificateKeyType;

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
}
