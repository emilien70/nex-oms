<?php

namespace Modules\Invoices\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Support\InvoiceBulkSelection;

class InvoiceBulkPdfRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    public function authorize(): bool
    {
        return true;
    }

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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('invoice_ids');

        $invoiceIds = InvoiceBulkSelection::decodeIds($this->input('selection'));

        if ($invoiceIds !== null) {
            $this->merge(['invoice_ids' => $invoiceIds]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $invoiceIds = $this->input('invoice_ids', []);

            if (! is_array($invoiceIds)
                || Invoice::query()->whereIntegerInRaw('id', $invoiceIds)->count() !== count($invoiceIds)) {
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
        return [
            'selection.required' => 'Brakuje danych zaznaczonych faktur.',
            'selection.string' => 'Dane zaznaczonych faktur są nieprawidłowe.',
            'selection.json' => 'Dane zaznaczonych faktur są nieprawidłowe.',
            'invoice_ids.required' => 'Zaznacz co najmniej jedną fakturę do wydruku.',
            'invoice_ids.array' => 'Lista faktur do wydruku jest nieprawidłowa.',
            'invoice_ids.min' => 'Zaznacz co najmniej jedną fakturę do wydruku.',
            'invoice_ids.max' => 'Jednorazowo można wydrukować maksymalnie 1000 faktur.',
            'invoice_ids.*.integer' => 'Lista faktur do wydruku jest nieprawidłowa.',
            'invoice_ids.*.distinct' => 'Lista faktur do wydruku zawiera duplikaty.',
            'invoice_ids.*.exists' => 'Jedna z zaznaczonych faktur już nie istnieje.',
        ];
    }

    protected function invalidInvoiceIdMessage(): string
    {
        return $this->messages()['invoice_ids.*.integer'];
    }
}
