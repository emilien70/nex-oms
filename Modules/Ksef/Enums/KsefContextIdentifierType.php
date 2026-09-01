<?php

namespace Modules\Ksef\Enums;

enum KsefContextIdentifierType: string
{
    case Nip = 'Nip';
    case InternalId = 'InternalId';
    case NipVatUe = 'NipVatUe';
    case PeppolId = 'PeppolId';
}
