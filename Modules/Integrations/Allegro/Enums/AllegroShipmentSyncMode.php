<?php

namespace Modules\Integrations\Allegro\Enums;

enum AllegroShipmentSyncMode: string
{
    case Disabled = 'disabled';
    case Immediate = 'immediate';
    case Status = 'status';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Nie przekazuj',
            self::Immediate => 'Po uzyskaniu numeru nadania',
            self::Status => 'Po przejściu do wybranego statusu OMS',
        };
    }
}
