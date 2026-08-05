<?php

namespace Modules\Invoices\Http\Requests;

class ProformaBulkPdfRequest extends InvoiceBulkPdfRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_ids.required' => 'Zaznacz co najmniej jedną Pro formę do wydruku.',
            'invoice_ids.array' => 'Lista Pro form do wydruku jest nieprawidłowa.',
            'invoice_ids.min' => 'Zaznacz co najmniej jedną Pro formę do wydruku.',
            'invoice_ids.max' => 'Jednorazowo można wydrukować maksymalnie 100 Pro form.',
            'invoice_ids.*.integer' => 'Lista Pro form do wydruku jest nieprawidłowa.',
            'invoice_ids.*.distinct' => 'Lista Pro form do wydruku zawiera duplikaty.',
            'invoice_ids.*.exists' => 'Jedna z zaznaczonych Pro form już nie istnieje.',
        ];
    }
}
