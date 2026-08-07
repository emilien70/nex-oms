<?php

namespace Modules\Invoices\Http\Requests;

class CorrectionBulkPdfRequest extends InvoiceBulkPdfRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_ids.required' => 'Zaznacz co najmniej jedną Korektę.',
            'invoice_ids.array' => 'Lista zaznaczonych Korekt jest nieprawidłowa.',
            'invoice_ids.min' => 'Zaznacz co najmniej jedną Korektę.',
            'invoice_ids.max' => 'Jednorazowo można wydrukować maksymalnie 100 Korekt.',
            'invoice_ids.*.integer' => 'Identyfikator Korekty jest nieprawidłowy.',
            'invoice_ids.*.distinct' => 'Ta sama Korekta została zaznaczona więcej niż raz.',
            'invoice_ids.*.exists' => 'Jedna z zaznaczonych Korekt już nie istnieje.',
        ];
    }
}
