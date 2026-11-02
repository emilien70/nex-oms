<?php

namespace Modules\Integrations\VonHalsky\Enums;

enum VonHalskyOrderAction: string
{
    case Accept = 'accept';
    case Cancel = 'cancel';

    public function label(): string
    {
        return $this === self::Accept ? 'Zaakceptowano' : 'Anulowane';
    }

    public function endpoint(): string
    {
        return $this === self::Accept ? 'accept' : 'refuse';
    }
}
