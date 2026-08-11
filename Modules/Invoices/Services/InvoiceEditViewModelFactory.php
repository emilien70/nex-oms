<?php

namespace Modules\Invoices\Services;

use App\Support\CountryCatalog;
use Modules\Invoices\Models\Invoice;

class InvoiceEditViewModelFactory
{
    public function __construct(
        private readonly CountryCatalog $countries,
        private readonly InvoicePdfCurrencyConversionPresenter $currencyPresenter,
        private readonly CorrectionSeriesResolver $correctionSeries,
        private readonly InvoiceMoneyFormatter $moneyFormatter,
    ) {}

    /** @return array<string, mixed> */
    public function make(Invoice $invoice): array
    {
        $invoice->loadMissing(['order.items', 'items', 'series']);

        return [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'countries' => $this->countries->all(),
            'nbp' => $this->currencyPresenter->present($invoice),
            'correctionSeries' => $this->correctionSeries->active(),
            'moneyFormatter' => $this->moneyFormatter,
        ];
    }
}
