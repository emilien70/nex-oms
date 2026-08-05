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
                        $this->missingLockVersionMessage(),
                    );
                    break;
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $plural = $this->documentPluralGenitive();
        $singular = $this->documentSingularAccusative();

        return [
            'invoice_ids.required' => "Zaznacz co najmniej jedną {$singular} do usunięcia.",
            'invoice_ids.array' => "Lista {$plural} do usunięcia jest nieprawidłowa.",
            'invoice_ids.min' => "Zaznacz co najmniej jedną {$singular} do usunięcia.",
            'invoice_ids.max' => "Jednorazowo można usunąć maksymalnie 100 {$plural}.",
            'invoice_ids.*.integer' => "Lista {$plural} do usunięcia jest nieprawidłowa.",
            'invoice_ids.*.distinct' => "Lista {$plural} do usunięcia zawiera duplikaty.",
            'invoice_ids.*.exists' => "Jedna z zaznaczonych {$plural} już nie istnieje.",
            'lock_versions.required' => "Brakuje technicznych wersji zaznaczonych {$plural}.",
            'lock_versions.array' => "Techniczne wersje zaznaczonych {$plural} są nieprawidłowe.",
            'lock_versions.*.required' => $this->missingLockVersionMessage(),
            'lock_versions.*.integer' => "Techniczna wersja jednej z zaznaczonych {$plural} jest nieprawidłowa.",
            'lock_versions.*.min' => "Techniczna wersja jednej z zaznaczonych {$plural} jest nieprawidłowa.",
        ];
    }

    protected function documentSingularAccusative(): string
    {
        return 'Fakturę';
    }

    protected function documentPluralGenitive(): string
    {
        return 'Faktur';
    }

    protected function missingLockVersionMessage(): string
    {
        return "Brakuje technicznej wersji jednej z zaznaczonych {$this->documentPluralGenitive()}.";
    }
}
