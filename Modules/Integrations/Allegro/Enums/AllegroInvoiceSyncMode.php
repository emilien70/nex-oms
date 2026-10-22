<?php

namespace Modules\Integrations\Allegro\Enums;

enum AllegroInvoiceSyncMode: string
{
    public function correctionLabel(): string
    {
        return $this === self::Immediate ? 'Po zamknięciu korekty' : $this->label();
    }

    case Disabled = 'disabled';
    case Immediate = 'immediate';
    case Status = 'status';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Nie przekazuj',
            self::Immediate => 'Po wystawieniu faktury',
            self::Status => 'Po wybranym statusie OMS',
        };
    }
}
