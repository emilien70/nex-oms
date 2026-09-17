<?php

namespace Modules\Invoices\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Invoices\Http\Controllers\JpkTaxpayerProfileController;

class SaveJpkTaxpayerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expected_lock_version' => ['required', 'integer', 'min:0']];
    }

    public function messages(): array
    {
        return ['expected_lock_version.*' => 'Brak prawidłowej wersji profilu. Otwórz formularz ponownie.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(app(JpkTaxpayerProfileController::class)->form($this, $validator->errors()->all(), 422));
    }
}
