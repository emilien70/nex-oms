<?php

namespace Modules\Integrations\Allegro\Enums;

enum AllegroFulfillmentStatus: string
{
    case New = 'NEW';
    case Processing = 'PROCESSING';
    case ReadyForShipment = 'READY_FOR_SHIPMENT';
    case ReadyForPickup = 'READY_FOR_PICKUP';
    case Sent = 'SENT';
    case PickedUp = 'PICKED_UP';
    case Cancelled = 'CANCELLED';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nowe',
            self::Processing => 'W realizacji',
            self::ReadyForShipment => 'Do wysłania',
            self::ReadyForPickup => 'Do odbioru osobistego',
            self::Sent => 'Wysłane',
            self::PickedUp => 'Odebrane osobiście',
            self::Cancelled => 'Anulowane',
            self::Suspended => 'Wstrzymane',
        };
    }
}
