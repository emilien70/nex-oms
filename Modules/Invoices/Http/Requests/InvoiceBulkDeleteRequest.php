<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Validation\Validator;

class InvoiceBulkDeleteRequest extends InvoiceEditRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
            'lock_versions' => ['required', 'array'],
            'lock_versions.*' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $invoiceIds = $this->input('invoice_ids', []);
            $lockVersions = $this->input('lock_versions', []);

            if (! is_array($invoiceIds) || ! is_array($lockVersions)) {
                return;
            }

            foreach ($invoiceIds as $invoiceId) {
                if (! array_key_exists((string) $invoiceId, $lockVersions)
                    && ! array_key_exists((int) $invoiceId, $lockVersions)) {
                    $validator->errors()->add(
                        'lock_versions',
                        'Brakuje technicznej wersji jednej z zaznaczonych Faktur.',
                    );
                    break;
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_ids.required' => 'Zaznacz co najmniej jedną Fakturę do usunięcia.',
            'invoice_ids.array' => 'Lista Faktur do usunięcia jest nieprawidłowa.',
            'invoice_ids.min' => 'Zaznacz co najmniej jedną Fakturę do usunięcia.',
            'invoice_ids.max' => 'Jednorazowo można usunąć maksymalnie 100 Faktur.',
            'invoice_ids.*.integer' => 'Lista Faktur do usunięcia jest nieprawidłowa.',
            'invoice_ids.*.distinct' => 'Lista Faktur do usunięcia zawiera duplikaty.',
            'invoice_ids.*.exists' => 'Jedna z zaznaczonych Faktur już nie istnieje.',
            'lock_versions.required' => 'Brakuje technicznych wersji zaznaczonych Faktur.',
            'lock_versions.array' => 'Techniczne wersje zaznaczonych Faktur są nieprawidłowe.',
            'lock_versions.*.required' => 'Brakuje technicznej wersji jednej z zaznaczonych Faktur.',
            'lock_versions.*.integer' => 'Techniczna wersja jednej z zaznaczonych Faktur jest nieprawidłowa.',
            'lock_versions.*.min' => 'Techniczna wersja jednej z zaznaczonych Faktur jest nieprawidłowa.',
        ];
    }
}
