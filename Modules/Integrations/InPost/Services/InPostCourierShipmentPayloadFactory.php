<?php

namespace Modules\Integrations\InPost\Services;

use DomainException;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\OrderReferenceFormatter;

class InPostCourierShipmentPayloadFactory
{
    public function make(Shipment $shipment): array
    {
        $shipment->loadMissing(['order', 'courierAccount', 'parcels']);

        $payload = [
            'receiver' => $this->receiver($shipment),
            'sender' => $this->sender($shipment),
            'parcels' => $shipment->parcels
                ->values()
                ->map(fn ($parcel, int $index): array => [
                    'id' => 'parcel-'.($index + 1),
                    'dimensions' => [
                        'length' => $this->centimetersToMillimeters($parcel->length),
                        'width' => $this->centimetersToMillimeters($parcel->width),
                        'height' => $this->centimetersToMillimeters($parcel->height),
                        'unit' => 'mm',
                    ],
                    'weight' => [
                        'amount' => (float) $parcel->weight,
                        'unit' => 'kg',
                    ],
                    'is_non_standard' => (bool) $parcel->is_non_standard,
                ])
                ->all(),
            'service' => $shipment->service,
            'reference' => OrderReferenceFormatter::format($shipment->order_id),
            'custom_attributes' => [
                'sending_method' => 'dispatch_order',
            ],
        ];

        if ($payload['parcels'] === []) {
            throw new DomainException('Dodaj co najmniej jedna paczke do przesylki kurierskiej.');
        }

        if (filled($shipment->content_description)) {
            $payload['comments'] = mb_substr((string) $shipment->content_description, 0, 100);
        }

        if ((float) $shipment->cod_amount > 0) {
            $payload['cod'] = [
                'amount' => (float) $shipment->cod_amount,
                'currency' => $shipment->currency,
            ];
        }

        if ((float) $shipment->insurance_amount > 0) {
            $payload['insurance'] = [
                'amount' => (float) $shipment->insurance_amount,
                'currency' => $shipment->currency,
            ];
        }

        $additionalServices = array_values(array_filter(
            $shipment->additional_services ?? [],
            fn (mixed $service): bool => in_array($service, [
                Shipment::ADDITIONAL_SERVICE_SMS,
                Shipment::ADDITIONAL_SERVICE_EMAIL,
                Shipment::ADDITIONAL_SERVICE_SATURDAY,
                Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
            ], true),
        ));

        if ($additionalServices !== []) {
            $payload['additional_services'] = $additionalServices;
        }

        return $payload;
    }

    private function receiver(Shipment $shipment): array
    {
        $order = $shipment->order;
        $name = trim((string) $order->shipping_name);
        $company = trim((string) $order->shipping_company_name);

        if ($name === '' && $company === '') {
            throw new DomainException('Uzupelnij imie i nazwisko lub firme odbiorcy.');
        }

        [$firstName, $lastName] = $this->splitName($name);

        return array_filter([
            'company_name' => $company,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => trim((string) $order->customer_email),
            'phone' => $this->normalizePolishPhone((string) $order->customer_phone, 'odbiorcy'),
            'address' => $this->address([
                'street' => $order->shipping_street,
                'building_number' => $order->shipping_building_number,
                'apartment_number' => $order->shipping_apartment_number,
                'city' => $order->shipping_city,
                'post_code' => $order->shipping_postal_code,
                'country_code' => $order->shipping_country_code ?: 'PL',
            ], 'odbiorcy'),
        ], fn (mixed $value): bool => filled($value));
    }

    private function sender(Shipment $shipment): array
    {
        $account = $shipment->courierAccount;
        $contactName = trim((string) $account?->setting('sender_contact_name'));
        $company = trim((string) $account?->setting('sender_company_name'));

        if ($contactName === '' && $company === '') {
            throw new DomainException('Uzupelnij dane nadawcy w konfiguracji InPost Kurier.');
        }

        [$firstName, $lastName] = $this->splitName($contactName);

        return array_filter([
            'company_name' => $company,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => trim((string) $account?->setting('sender_email')),
            'phone' => $this->normalizePolishPhone((string) $account?->setting('sender_phone'), 'nadawcy'),
            'address' => $this->address([
                'street' => $account?->setting('sender_street'),
                'building_number' => $account?->setting('sender_building_number'),
                'apartment_number' => $account?->setting('sender_apartment_number'),
                'city' => $account?->setting('sender_city'),
                'post_code' => $account?->setting('sender_postal_code'),
                'country_code' => $account?->setting('sender_country_code', 'PL'),
            ], 'nadawcy'),
        ], fn (mixed $value): bool => filled($value));
    }

    private function address(array $data, string $owner): array
    {
        foreach (['street', 'building_number', 'city', 'post_code'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw new DomainException('Uzupelnij pelny adres '.$owner.' przed nadaniem przesylki.');
            }
        }

        $buildingNumber = trim((string) $data['building_number']);
        $apartmentNumber = trim((string) ($data['apartment_number'] ?? ''));

        if ($apartmentNumber !== '') {
            $buildingNumber .= '/'.$apartmentNumber;
        }

        return [
            'street' => trim((string) $data['street']),
            'building_number' => $buildingNumber,
            'city' => trim((string) $data['city']),
            'post_code' => trim((string) $data['post_code']),
            'country_code' => strtoupper(trim((string) ($data['country_code'] ?? 'PL'))),
        ];
    }

    private function normalizePolishPhone(string $phone, string $owner): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) !== 9) {
            throw new DomainException('Telefon '.$owner.' dla InPost musi miec 9 cyfr.');
        }

        return $digits;
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return [null, null];
        }

        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $lastName = array_pop($parts);

        return [implode(' ', $parts), $lastName];
    }

    private function centimetersToMillimeters(mixed $value): float
    {
        return round((float) $value * 10, 2);
    }
}
