<?php

namespace Modules\Invoices\Enums;

enum InvoiceOperationSource: string
{
    case Manual = 'manual';
    case Automation = 'automation';
    case Api = 'api';
    case Integration = 'integration';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ręcznie',
            self::Automation => 'Automatyzacja',
            self::Api => 'API',
            self::Integration => 'Integracja',
        };
    }
}
