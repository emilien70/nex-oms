<?php

namespace Modules\Integrations\VonHalsky\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVonHalskyAutomaticImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expected_lock_version' => ['required', 'integer', 'min:0'],
            'order_import_enabled' => ['required', 'boolean'],
            'order_import_interval_minutes' => ['required', 'integer', Rule::in([1, 10])],
            'order_import_started_at' => ['nullable', 'required_if:order_import_enabled,1', 'date_format:Y-m-d\TH:i', 'before_or_equal:now', 'after:2000-01-01']];
    }
}
