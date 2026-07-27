<?php

namespace Modules\Invoices\Enums;

enum InvoiceSaleDateSource: string
{
    case OrderDate = 'order_date';
    case PaymentOrIssue = 'payment_or_issue';
    case IssueDate = 'issue_date';

    public function label(): string
    {
        return match ($this) {
            self::OrderDate => 'Data zamówienia',
            self::PaymentOrIssue => 'Data płatności lub data wystawienia',
            self::IssueDate => 'Data wystawienia faktury',
        };
    }
}
