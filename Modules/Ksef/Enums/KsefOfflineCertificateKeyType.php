<?php

namespace Modules\Ksef\Enums;

enum KsefOfflineCertificateKeyType: string
{
    case Rsa = 'RSA';
    case Ec = 'EC';

    public function label(int $keySize, ?string $curve): string
    {
        return match ($this) {
            self::Rsa => "RSA {$keySize}",
            self::Ec => $curve === null ? "EC {$keySize}" : "EC {$curve}",
        };
    }
}
