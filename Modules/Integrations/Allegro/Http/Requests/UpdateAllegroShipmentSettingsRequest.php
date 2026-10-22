<?php

namespace Modules\Integrations\Allegro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Integrations\Allegro\Enums\AllegroShipmentSyncMode;

class UpdateAllegroShipmentSettingsRequest extends FormRequest
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
            'shipment_sync_mode' => ['required', Rule::enum(AllegroShipmentSyncMode::class)],
            'shipment_sync_status_setting_id' => ['nullable', 'required_if:shipment_sync_mode,status', 'integer',
                Rule::exists('order_status_settings', 'id')->whereNull('deleted_at')],
        ];
    }
}
