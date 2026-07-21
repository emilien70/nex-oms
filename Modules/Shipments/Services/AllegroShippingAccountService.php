<?php

namespace Modules\Shipments\Services;

use Modules\Shipments\Models\CourierAccount;

class AllegroShippingAccountService
{
    public function get(): CourierAccount
    {
        return CourierAccount::query()
            ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            ->first() ?? new CourierAccount([
                'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
                'name' => 'Wysylam z Allegro',
                'environment' => 'sandbox',
                'organization_id' => config('services.allegro_shipping.client_id'),
                'settings' => [
                    'label_format' => 'PDF',
                    'label_type' => 'A6',
                    'content_description_source' => 'order_id',
                    'reference_number_source' => 'order_id',
                    'default_weight' => 1,
                    'default_length' => 25,
                    'default_width' => 20,
                    'default_height' => 10,
                    'parcel_templates' => [],
                ],
            ]);
    }

    public function save(array $data): CourierAccount
    {
        $account = CourierAccount::query()->firstOrNew([
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
        ]);

        $account->fill([
            'name' => $data['name'],
            'environment' => $data['environment'],
            'organization_id' => $data['organization_id'],
            'is_active' => false,
            'last_error' => null,
            'settings' => array_merge($account->settings ?? [], [
                'label_format' => $data['label_format'],
                'label_type' => $data['label_type'],
                'content_description_source' => $data['content_description_source'],
                'reference_number_source' => $data['reference_number_source'],
                'default_weight' => $data['default_weight'],
                'default_length' => $data['default_length'],
                'default_width' => $data['default_width'],
                'default_height' => $data['default_height'],
            ]),
        ]);

        foreach (['api_token', 'api_secret', 'api_refresh_token'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $account->{$secret} = $data[$secret];
            }
        }

        $account->is_active = (bool) ($data['is_active'] ?? false) && filled($account->resolvedApiToken());

        $account->save();

        return $account;
    }
}
