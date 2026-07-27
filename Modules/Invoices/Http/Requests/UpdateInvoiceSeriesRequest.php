<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Modules\Invoices\Models\InvoiceSeries;

class UpdateInvoiceSeriesRequest extends InvoiceSeriesRequest
{
    protected function prepareForValidation(): void
    {
        $series = $this->series();

        if ($series->is_system) {
            $this->merge([
                'document_type' => $series->document_type->value,
                'is_active' => true,
            ]);
        }

        parent::prepareForValidation();
    }

    protected function uniqueNameRule(): Unique
    {
        return Rule::unique('invoice_series', 'name')
            ->where('document_type', (string) $this->input('document_type'))
            ->ignore($this->series()->getKey());
    }

    private function series(): InvoiceSeries
    {
        $series = $this->route('series');

        abort_unless($series instanceof InvoiceSeries, 404);

        return $series;
    }
}
