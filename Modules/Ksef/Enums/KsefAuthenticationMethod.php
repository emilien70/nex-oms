<?php

namespace Modules\Ksef\Enums;

enum KsefAuthenticationMethod: string
{
    case Token = 'token';
    case Certificate = 'certificate';

    public function label(): string
    {
        return match ($this) {
            self::Token => 'Token KSeF',
            self::Certificate => 'Certyfikat KSeF',
        };
    }
}
