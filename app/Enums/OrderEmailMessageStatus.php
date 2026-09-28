<?php

namespace App\Enums;

enum OrderEmailMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'W kolejce',
            self::Sending => 'Wysyłanie',
            self::Sent => 'Wysłana',
            self::Failed => 'Błąd wysyłki',
            self::Unknown => 'Niepewny wynik wysyłki',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Unknown], true);
    }
}
