<?php

namespace Modules\Invoices\Enums;

enum InvoicePaymentDueMode: string
{
    case None = 'none';
    case Order = 'order';
    case DaysFromIssue = 'days_from_issue';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Nie pokazuj terminu płatności',
            self::Order => 'Dane płatności z zamówienia',
            self::DaysFromIssue => 'Liczba dni od daty wystawienia',
        };
    }
}
