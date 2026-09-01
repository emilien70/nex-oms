<?php

namespace Modules\Ksef\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;

final class KsefOfflineCertificateService
{
    public function __construct(
        private readonly KsefOfflineCertificateMaterialService $materialService,
    ) {}

    public function import(
        KsefEnvironment $environment,
        ?string $label,
        string $certificateContents,
        string $privateKeyContents,
        ?string $passphrase,
    ): KsefOfflineCertificate {
        $material = $this->materialService->inspect(
            $certificateContents,
            $privateKeyContents,
            $passphrase,
        );

        return DB::transaction(function () use ($environment, $label, $material): KsefOfflineCertificate {
            $duplicate = KsefOfflineCertificate::query()
                ->where('environment', $environment->value)
                ->where('certificate_serial_number', $material->certificateSerialNumber)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'offline_certificate' => 'Certyfikat Offline o tym numerze jest już zapisany dla wybranego środowiska.',
                ]);
            }

            return KsefOfflineCertificate::query()->create([
                'environment' => $environment,
                'certificate_serial_number' => $material->certificateSerialNumber,
                'label' => $label,
                'certificate_pem' => $material->certificatePem,
                'private_key_pem' => $material->privateKeyPem,
                'valid_from' => $material->validFrom,
                'valid_until' => $material->validUntil,
                'fingerprint_sha256' => $material->fingerprintSha256,
                'key_type' => $material->keyType,
                'key_size' => $material->keySize,
                'curve' => $material->curve,
            ]);
        });
    }

    public function setPreferred(
        KsefOfflineCertificate $certificate,
        KsefEnvironment $environment,
    ): void {
        if ($certificate->environment !== $environment) {
            throw ValidationException::withMessages([
                'environment' => 'Certyfikat Offline można wybrać tylko w jego własnym środowisku.',
            ]);
        }

        DB::transaction(function () use ($certificate, $environment): void {
            KsefOfflineCertificate::query()
                ->whereKey($certificate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            KsefOfflineCertificateSelection::query()->updateOrCreate(
                ['environment' => $environment->value],
                ['offline_certificate_id' => $certificate->getKey()],
            );
        });
    }

    public function delete(KsefOfflineCertificate $certificate): void
    {
        $certificate->delete();
    }

    public function forConfiguration(): Collection
    {
        return KsefOfflineCertificate::query()
            ->with('preferredSelection:id,offline_certificate_id')
            ->orderByRaw("CASE environment WHEN 'test' THEN 0 WHEN 'demo' THEN 1 ELSE 2 END")
            ->orderByDesc('valid_until')
            ->orderBy('certificate_serial_number')
            ->get();
    }
}
