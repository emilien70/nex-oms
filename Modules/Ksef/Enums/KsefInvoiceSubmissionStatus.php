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

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Accepted,
            self::Rejected,
            self::TechnicalFailed,
        ], true);
    }

    public function blocksDocumentEdit(): bool
    {
        return true;
    }

    public function blocksDocumentDeletion(): bool
    {
        return true;
    }

    public function allowsStatusRefresh(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::Processing,
        ], true);
    }

    public function allowsReconciliation(): bool
    {
        return $this === self::Uncertain;
    }

    public function allowsNewAttempt(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::TechnicalFailed,
        ], true);
    }

    public function requiresReconciliation(): bool
    {
        return $this === self::Uncertain;
    }

    public function blocksNewAttempt(): bool
    {
        return ! $this->allowsNewAttempt();
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Preparing => in_array($status, [
                self::SessionOpened,
                self::TechnicalFailed,
            ], true),
            self::SessionOpened => in_array($status, [
                self::Submitted,
                self::TechnicalFailed,
                self::Uncertain,
            ], true),
            self::Submitted, self::Processing, self::Uncertain => in_array($status, [
                self::Processing,
                self::Accepted,
                self::Rejected,
                self::Uncertain,
            ], true),
            self::Accepted, self::Rejected, self::TechnicalFailed => false,
        };
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
