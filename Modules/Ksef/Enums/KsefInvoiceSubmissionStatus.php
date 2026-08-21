<?php

namespace Modules\Ksef\Enums;

enum KsefInvoiceSubmissionStatus: string
{
    case Preparing = 'preparing';
    case SessionOpened = 'session_opened';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case TechnicalFailed = 'technical_failed';
    case Uncertain = 'uncertain';

    public function blocksNewAttempt(): bool
    {
        return in_array($this, [
            self::Preparing,
            self::SessionOpened,
            self::Submitted,
            self::Processing,
            self::Accepted,
            self::Uncertain,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Przygotowywanie',
            self::SessionOpened => 'Sesja otwarta',
            self::Submitted => 'Wysłana',
            self::Processing => 'Przetwarzanie',
            self::Accepted => 'Przyjęta',
            self::Rejected => 'Odrzucona',
            self::TechnicalFailed => 'Błąd techniczny',
            self::Uncertain => 'Stan niepewny',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Preparing, self::Processing => 'info',
            self::SessionOpened, self::Uncertain => 'warning',
            self::Submitted => 'primary',
            self::Accepted => 'success',
            self::Rejected, self::TechnicalFailed => 'danger',
        };
    }
}
