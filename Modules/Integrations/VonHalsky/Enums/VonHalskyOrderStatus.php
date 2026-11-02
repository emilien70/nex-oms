<?php

namespace Modules\Integrations\VonHalsky\Enums;

enum VonHalskyOrderStatus: string
{
    case Created = 'CREATED';
    case Accepted = 'ACCEPTED';
    case Canceled = 'CANCELED';
    case Refused = 'REFUSED';
    case Rejected = 'REJECTED';
    case Unknown = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Utworzone',
            self::Accepted => 'Zaakceptowane',
            self::Canceled => 'Anulowane przez kupującego',
            self::Refused => 'Odrzucone przez sprzedawcę',
            self::Rejected => 'Odrzucone przez platformę',
            self::Unknown => 'Status wymaga sprawdzenia',
        };
    }

    public function isNegative(): bool
    {
        return in_array($this, [self::Canceled, self::Refused, self::Rejected], true);
    }

    public function warning(): ?string
    {
        return match ($this) {
            self::Canceled => 'Zamówienie zostało anulowane przez kupującego w Von Halsky.',
            self::Refused => 'Zamówienie zostało odrzucone przez sprzedawcę w Von Halsky.',
            self::Rejected => 'Zamówienie zostało odrzucone przez platformę Von Halsky.',
            self::Unknown => 'Status zamówienia w Von Halsky wymaga sprawdzenia.',
            default => null,
        };
    }
}
