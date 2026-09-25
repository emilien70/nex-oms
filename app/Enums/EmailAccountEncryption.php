<?php

namespace App\Enums;

enum EmailAccountEncryption: string
{
    case StartTls = 'starttls';
    case Tls = 'tls';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::StartTls => 'STARTTLS',
            self::Tls => 'SSL/TLS',
            self::None => 'Bez szyfrowania',
        };
    }
}
