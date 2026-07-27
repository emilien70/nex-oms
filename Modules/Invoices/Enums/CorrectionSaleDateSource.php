<?php

namespace Modules\Invoices\Enums;

enum CorrectionSaleDateSource: string
{
    case SourceInvoice = 'source_invoice';
    case IssueDate = 'issue_date';

    public function label(): string
    {
        return match ($this) {
            self::SourceInvoice => 'Taka sama jak na fakturze korygowanej',
            self::IssueDate => 'Taka sama jak data wystawienia korekty',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SourceInvoice => 'Data sprzedaży zostanie pobrana ze snapshotu faktury źródłowej.',
            self::IssueDate => 'Data sprzedaży będzie równa dacie wystawienia korekty.',
        };
    }
}
