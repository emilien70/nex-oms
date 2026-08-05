<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $textFields = ['full_number', 'buyer', 'company', 'tax_id', 'source'];
        $normalized = [];

        foreach ($textFields as $field) {
            if ($this->exists($field)) {
                $value = trim((string) $this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        if ($this->exists('currency')) {
            $value = strtoupper(trim((string) $this->input('currency')));
            $normalized['currency'] = $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'series_id' => ['nullable', 'integer', 'exists:invoice_series,id'],
            'number' => ['nullable', 'integer', 'min:1'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'full_number' => ['nullable', 'string', 'max:120'],
            'buyer' => ['nullable', 'string', 'max:160'],
            'company' => ['nullable', 'string', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'total_from' => ['nullable', 'decimal:0,2', 'min:0'],
            'total_to' => ['nullable', 'decimal:0,2', 'min:0'],
            'issue_from' => ['nullable', 'date_format:Y-m-d'],
            'issue_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_from'],
            'sale_from' => ['nullable', 'date_format:Y-m-d'],
            'sale_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sale_from'],
            'source' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sort' => ['nullable', Rule::in(['number', 'order', 'issue_date', 'buyer', 'gross'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ];
    }
}
