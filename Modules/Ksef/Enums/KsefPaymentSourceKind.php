<?php

namespace Modules\Ksef\Enums;

enum KsefPaymentSourceKind: string
{
    case PaymentMethod = 'payment_method';
    case CashOnDelivery = 'cash_on_delivery';
}
