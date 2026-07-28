<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Modules\Invoices\Models\InvoiceSeries;

class AdditionalInformationRenderer
{
    public const SELLER_NOTES_TOKEN = '[uwagi_sprzedawcy]';

    public function render(InvoiceSeries $series, Order $order): string
    {
        return str_replace(
            self::SELLER_NOTES_TOKEN,
            (string) ($order->notes ?? ''),
            (string) ($series->additional_information_template ?? ''),
        );
    }
}
