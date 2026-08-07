<?php

namespace Modules\Invoices\Http\Requests;

use Closure;
use Illuminate\Validation\Validator;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Support\InvoiceBulkSelection;

class InvoiceBulkDeleteRequest extends InvoiceEditRequest
{
    protected $stopOnFirstFailure = true;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'selection' => ['required', 'string', 'json'],
            'invoice_ids' => ['required', 'array', 'min:1', 'max:'.InvoiceBulkSelection::MAX_DOCUMENTS],
            'invoice_ids.*' => [
                'bail',
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 1) {
                        $fail($this->invalidInvoiceIdMessage());
                    }
                },
                'distinct:strict',
            ],
            'lock_versions' => ['required', 'array'],
            'lock_versions.*' => [
                'bail',
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 1) {
                        $fail($this->invalidLockVersionMessage());
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('invoice_ids');
        $this->request->remove('lock_versions');

        $selection = InvoiceBulkSelection::decodeLockVersions($this->input('selection'));

        if ($selection !== null) {
            $this->merge($selection);
        }
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

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

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (Invoice::query()->whereIntegerInRaw('id', $invoiceIds)->count() !== count($invoiceIds)) {
                $validator->errors()->add(
                    'invoice_ids',
                    $this->messages()['invoice_ids.*.exists'],
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $plural = $this->documentPluralGenitive();
        $singular = $this->documentSingularAccusative();

        return [
            'selection.required' => "Brakuje danych zaznaczonych {$plural}.",
            'selection.string' => "Dane zaznaczonych {$plural} są nieprawidłowe.",
            'selection.json' => "Dane zaznaczonych {$plural} są nieprawidłowe.",
            'invoice_ids.required' => "Zaznacz co najmniej jedną {$singular} do usunięcia.",
            'invoice_ids.array' => "Lista {$plural} do usunięcia jest nieprawidłowa.",
            'invoice_ids.min' => "Zaznacz co najmniej jedną {$singular} do usunięcia.",
            'invoice_ids.max' => "Jednorazowo można usunąć maksymalnie 1000 {$plural}.",
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

    protected function invalidInvoiceIdMessage(): string
    {
        return $this->messages()['invoice_ids.*.integer'];
    }

    protected function invalidLockVersionMessage(): string
    {
        return $this->messages()['lock_versions.*.integer'];
    }
}
