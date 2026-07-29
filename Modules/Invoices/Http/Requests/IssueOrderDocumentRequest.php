<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueOrderDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_series_id' => ['required', 'integer', 'exists:invoice_series,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_series_id.required' => 'Wybierz serię numeracji.',
            'invoice_series_id.integer' => 'Wybrana seria numeracji jest nieprawidłowa.',
            'invoice_series_id.exists' => 'Wybrana seria numeracji nie istnieje.',
        ];
    }
}
