<?php

namespace Modules\Ksef\Enums;

enum KsefOfflineDeliveryDocumentType: string
{
    case TransactionConfirmation = 'transaction_confirmation';
    case OfflineInvoice = 'offline_invoice';
}
