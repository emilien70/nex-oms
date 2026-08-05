<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceBulkPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_ids.required' => 'Zaznacz co najmniej jedną fakturę do wydruku.',
            'invoice_ids.array' => 'Lista faktur do wydruku jest nieprawidłowa.',
            'invoice_ids.min' => 'Zaznacz co najmniej jedną fakturę do wydruku.',
            'invoice_ids.max' => 'Jednorazowo można wydrukować maksymalnie 100 faktur.',
            'invoice_ids.*.integer' => 'Lista faktur do wydruku jest nieprawidłowa.',
            'invoice_ids.*.distinct' => 'Lista faktur do wydruku zawiera duplikaty.',
            'invoice_ids.*.exists' => 'Jedna z zaznaczonych faktur już nie istnieje.',
        ];
    }
}
