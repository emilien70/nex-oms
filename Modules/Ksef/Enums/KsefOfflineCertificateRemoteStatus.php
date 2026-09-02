<?php

namespace Modules\Ksef\Enums;

enum KsefOfflineCertificateRemoteStatus: string
{
    case Active = 'Active';
    case Blocked = 'Blocked';
    case Revoked = 'Revoked';
    case Expired = 'Expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktywny',
            self::Blocked => 'Zablokowany',
            self::Revoked => 'Unieważniony',
            self::Expired => 'Wygasły',
        };
    }
}
