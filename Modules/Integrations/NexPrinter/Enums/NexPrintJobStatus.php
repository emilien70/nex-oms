<?php

namespace Modules\Integrations\NexPrinter\Enums;

enum NexPrintJobStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Downloaded = 'downloaded';
    case Spooled = 'spooled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'W kolejce',
            self::Claimed => 'Pobrane przez program',
            self::Downloaded => 'Pobrano PDF',
            self::Spooled => 'Przekazano do drukarki',
            self::Failed => 'Błąd',
            self::Cancelled => 'Anulowane',
            self::Expired => 'Wygasło',
        };
    }
}
