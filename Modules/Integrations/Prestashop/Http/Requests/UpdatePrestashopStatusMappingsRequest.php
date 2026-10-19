<?php

namespace Modules\Integrations\Prestashop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrestashopStatusMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inbound' => ['present', 'array'],
            'inbound.*' => ['nullable', 'string', 'max:191'],
            'outbound' => ['present', 'array'],
            'outbound.*' => ['nullable', 'integer', 'min:1'],
            'notify_customer_on_status_change' => ['required', 'boolean'],
            'status_sync_enabled' => ['required', 'boolean'],
            'status_sync_interval_minutes' => ['required', 'integer', 'in:1,5,10'],
        ];
    }

    public function messages(): array
    {
        return [
            'inbound.present' => 'Brakuje konfiguracji statusów pobieranych z PrestaShop.',
            'outbound.present' => 'Brakuje konfiguracji statusów wysyłanych do PrestaShop.',
            'outbound.*.integer' => 'Wybrany status PrestaShop jest nieprawidłowy.',
            'status_sync_interval_minutes.in' => 'Wybierz obsługiwaną częstotliwość synchronizacji statusów.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'notify_customer_on_status_change' => $this->boolean('notify_customer_on_status_change'),
            'status_sync_enabled' => $this->boolean('status_sync_enabled'),
        ]);
    }
}
