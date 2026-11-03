<?php

namespace App\Http\Requests;

use App\Services\OrderReturnParcelService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveOrderReturnParcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->waybill)) {
            $this->merge(['waybill' => trim($this->waybill)]);
        }
    }

    public function rules(): array
    {
        return ['carrier_id' => ['required', Rule::in(array_keys(OrderReturnParcelService::CARRIERS))],
            'waybill' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/D'],
            'expected_lock_version' => ['required', 'integer', 'min:0']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->carrier_id === 'INPOST' && (! is_string($this->waybill) || preg_match('/^\d{24}$/D', $this->waybill) !== 1)) {
                $validator->errors()->add('waybill', 'Numer przesyłki InPost musi zawierać 24 cyfry.');
            }
        }];
    }
}
