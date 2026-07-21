<?php

namespace Modules\Shipments\Services;

use Modules\Shipments\Models\CourierAccount;

class InPostAccountService
{
    public function get(): CourierAccount
    {
        return CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_INPOST_LOCKERS)
            ->first() ?? new CourierAccount([
                'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
                'name' => 'InPost Paczkomaty',
                'environment' => 'sandbox',
                'organization_id' => config('services.inpost.organization_id'),
                'settings' => [
                    'default_parcel_template' => 'medium',
                    'label_format' => 'Pdf',
                    'label_type' => 'A6',
                    'content_description_source' => 'order_id',
                    'sending_method' => 'dispatch_order',
                    'sender_country_code' => 'PL',
                ],
            ]);
    }

    public function save(array $data): CourierAccount
    {
        $account = CourierAccount::query()->firstOrNew([
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]);

        $account->fill([
            'name' => $data['name'],
            'environment' => $data['environment'],
            'organization_id' => $data['organization_id'],
            'settings' => [
                'default_parcel_template' => $data['default_parcel_template'],
                'label_format' => $data['label_format'],
                'label_type' => $data['label_type'],
                'content_description_source' => $data['content_description_source'],
                'sending_method' => $data['sending_method'],
                'sender_point_id' => $data['sender_point_id'] ?? null,
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
            ],
            'is_active' => (bool) ($data['is_active'] ?? false),
            'last_error' => null,
        ]);

        if (filled($data['api_token'] ?? null)) {
            $account->api_token = $data['api_token'];
        }

        $account->save();

        return $account;
    }
}
