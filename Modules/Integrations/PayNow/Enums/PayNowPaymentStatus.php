<?php

namespace Modules\Integrations\PayNow\Enums;

enum PayNowPaymentStatus: string
{
    case Created = 'NEW';
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case Error = 'ERROR';
    case Expired = 'EXPIRED';
    case Abandoned = 'ABANDONED';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Nowa',
            self::Pending => 'Oczekuje',
            self::Confirmed => 'Potwierdzona',
            self::Rejected => 'Odrzucona',
            self::Error => 'Błąd',
            self::Expired => 'Wygasła',
            self::Abandoned => 'Porzucona',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::Rejected,
            self::Error,
            self::Expired,
            self::Abandoned,
        ], true);
    }
}
