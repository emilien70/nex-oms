<?php

namespace Modules\Ksef\Enums;

enum KsefPublicKeyUsage: string
{
    case TokenEncryption = 'KsefTokenEncryption';
    case SymmetricKeyEncryption = 'SymmetricKeyEncryption';
}
