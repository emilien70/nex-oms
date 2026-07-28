<?php

namespace Modules\Invoices\Enums;

enum ProformaOperationStatus: string
{
    case Created = 'created';
    case Refreshed = 'refreshed';
    case Unchanged = 'unchanged';
}
