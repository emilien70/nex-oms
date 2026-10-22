<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\Allegro\Enums\AllegroInvoiceSyncMode;

class UpdateAllegroCorrectionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identity' => ['required', 'string', 'max:255'],
            'configuration_revision' => ['required', 'integer', 'min:0'],
            'correction_sync_mode' => ['required', Rule::enum(AllegroInvoiceSyncMode::class)],
            'correction_sync_status_setting_id' => ['nullable', 'required_if:correction_sync_mode,status', 'integer',
                Rule::exists('order_status_settings', 'id')->whereNull('deleted_at')],
        ];
    }
}
