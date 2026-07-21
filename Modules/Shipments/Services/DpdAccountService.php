<?php

namespace Modules\Shipments\Services;

use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class DpdAccountService
{
    public function get(): CourierAccount
    {
        return CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_DPD)
            ->first() ?? new CourierAccount([
                'provider' => CourierAccount::PROVIDER_DPD,
                'name' => 'DPD',
                'environment' => 'sandbox',
                'organization_id' => config('services.dpd.master_fid'),
                'settings' => [
                    'api_login' => config('services.dpd.login'),
                    'info_channel' => config('services.dpd.info_channel'),
                    'default_service' => Shipment::SERVICE_DPD_DOMESTIC,
                    'default_weight' => 1,
                    'default_length' => 25,
                    'default_width' => 20,
                    'default_height' => 10,
                    'default_insurance_amount' => 0,
                    'label_format' => 'PDF',
                    'label_type' => 'LABEL',
                    'content_description_source' => 'order_id',
                    'sender_country_code' => 'PL',
                    'parcel_templates' => [],
                ],
            ]);
    }

    public function save(array $data): CourierAccount
    {
        $account = CourierAccount::query()->firstOrNew([
            'provider' => CourierAccount::PROVIDER_DPD,
        ]);

        $account->fill([
            'name' => $data['name'],
            'environment' => $data['environment'],
            'organization_id' => $data['organization_id'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'last_error' => null,
            'settings' => array_merge($account->settings ?? [], [
                'api_login' => $data['api_login'],
                'info_channel' => $data['info_channel'],
                'default_service' => $data['default_service'],
                'default_weight' => $data['default_weight'],
                'default_length' => $data['default_length'],
                'default_width' => $data['default_width'],
                'default_height' => $data['default_height'],
                'default_insurance_amount' => $data['default_insurance_amount'] ?? 0,
                'label_format' => $data['label_format'],
                'label_type' => $data['label_type'],
                'content_description_source' => $data['content_description_source'],
                'default_saturday' => (bool) ($data['default_saturday'] ?? false),
                'default_return_documents' => (bool) ($data['default_return_documents'] ?? false),
                'sender_company_name' => $data['sender_company_name'],
                'sender_contact_name' => $data['sender_contact_name'],
                'sender_street' => $data['sender_street'],
                'sender_building_number' => $data['sender_building_number'],
                'sender_apartment_number' => $data['sender_apartment_number'] ?? null,
                'sender_postal_code' => $data['sender_postal_code'],
                'sender_city' => $data['sender_city'],
                'sender_country_code' => strtoupper($data['sender_country_code']),
                'sender_phone' => $data['sender_phone'],
                'sender_email' => $data['sender_email'],
            ]),
        ]);

        if (filled($data['api_token'] ?? null)) {
            $account->api_token = $data['api_token'];
        }

        $account->save();

        return $account;
    }
}
