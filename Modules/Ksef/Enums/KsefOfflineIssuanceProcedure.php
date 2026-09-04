<?php

namespace Modules\Ksef\Enums;

enum KsefOfflineIssuanceProcedure: string
{
    case Offline24 = 'offline24';
    case PlannedUnavailability = 'planned_unavailability';
    case Failure = 'failure';

    public function label(): string
    {
        return match ($this) {
            self::Offline24 => 'Offline24',
            self::PlannedUnavailability => 'Offline – niedostępność',
            self::Failure => 'Tryb awaryjny',
        };
    }
}
