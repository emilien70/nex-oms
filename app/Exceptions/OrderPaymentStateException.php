<?php

namespace App\Exceptions;

use Modules\Invoices\Exceptions\InvoiceDomainException;

class OrderPaymentStateException extends InvoiceDomainException
{
    public function __construct(string $message = 'Status płatności i kwota zapłacona są niespójne.')
    {
        parent::__construct('order_payment_state_invalid', $message);
    }
}
