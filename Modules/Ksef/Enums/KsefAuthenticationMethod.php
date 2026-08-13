<?php

namespace Modules\Ksef\Enums;

enum KsefAuthenticationMethod: string
{
    case Token = 'token';

    public function label(): string
    {
        return 'Token KSeF';
    }
}
