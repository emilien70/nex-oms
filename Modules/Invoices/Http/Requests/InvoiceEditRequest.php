<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class InvoiceEditRequest extends FormRequest
{
    protected const IMMUTABLE_FIELDS = [
        'order_id', 'invoice_series_id', 'document_type', 'status', 'number',
        'sequence_number', 'numbering_period_key', 'number_format_snapshot',
        'series_name_snapshot', 'currency', 'issued_at', 'lock_version',
        'source_snapshot_hash', 'corrected_invoice_id', 'previous_correction_id',
        'superseded_by_invoice_id', 'proforma_superseded_at',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, 'Tego pola Faktury nie można zmieniać.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expected_lock_version.required' => 'Brakuje technicznej wersji blokady Faktury.',
            'expected_lock_version.integer' => 'Techniczna wersja blokady Faktury jest nieprawidłowa.',
            'expected_lock_version.min' => 'Techniczna wersja blokady Faktury jest nieprawidłowa.',
        ];
    }
}
