<?php

namespace Modules\Ksef\Enums;

enum KsefInvoiceTransportMode: string
{
    case Online = 'online';
    case OrdinaryOffline = 'ordinary_offline';
    case OfflineTechnicalCorrection = 'offline_technical_correction';

    public function isOffline(): bool
    {
        return $this !== self::Online;
    }

    public function isTechnicalCorrection(): bool
    {
        return $this === self::OfflineTechnicalCorrection;
    }
}
