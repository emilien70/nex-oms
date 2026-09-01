<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ksef\Enums\KsefEnvironment;

class KsefOfflineCertificateSelection extends Model
{
    protected $fillable = [
        'environment',
        'offline_certificate_id',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(KsefOfflineCertificate::class, 'offline_certificate_id');
    }
}
