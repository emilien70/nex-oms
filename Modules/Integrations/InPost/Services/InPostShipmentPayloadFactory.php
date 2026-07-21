<?php

namespace Modules\Integrations\InPost\Services;

use DomainException;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\OrderReferenceFormatter;

class InPostShipmentPayloadFactory
{
    private const ADDITIONAL_SERVICE_WEEKEND = 'weekend_delivery';

    public function make(Shipment $shipment): array
    {
        $order = $shipment->order;
        $email = trim((string) $order->customer_email);
        $phone = $this->normalizePolishPhone((string) $order->customer_phone);
        $targetPoint = trim((string) $shipment->target_point_id);

        if ($email === '') {
            throw new DomainException('Uzupelnij e-mail klienta przed nadaniem przesylki InPost.');
        }

        if ($targetPoint === '') {
            throw new DomainException('Wybierz lub wpisz ID Paczkomatu docelowego.');
        }

        [$firstName, $lastName] = $this->splitName((string) $order->shipping_name);
        $receiver = array_filter([
            'company_name' => $order->shipping_company_name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
        ], fn (mixed $value): bool => filled($value));

        $payload = [
            'receiver' => $receiver,
            'parcels' => [
                'template' => $shipment->parcel_template,
            ],
            'custom_attributes' => [
                'sending_method' => $shipment->sending_method,
                'target_point' => $targetPoint,
            ],
            'service' => $shipment->service,
            'reference' => $this->reference($shipment),
        ];

        if (filled($shipment->content_description)) {
            $payload['comments'] = mb_substr((string) $shipment->content_description, 0, 100);
        }

        if ($shipment->sending_method === 'parcel_locker' && filled($shipment->dropoff_point_id)) {
            $payload['custom_attributes']['dropoff_point'] = $shipment->dropoff_point_id;
        }

        if ($sender = $this->sender($shipment)) {
            $payload['sender'] = $sender;
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

        if (in_array(self::ADDITIONAL_SERVICE_WEEKEND, $this->additionalServices($shipment), true)) {
            $payload['end_of_week_collection'] = true;
        }

        return $payload;
    }

    private function additionalServices(Shipment $shipment): array
    {
        $services = $shipment->getAttribute('additional_services');

        if (is_string($services)) {
            $services = json_decode($services, true);
        }

        return is_array($services) ? $services : [];
    }

    private function normalizePolishPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) !== 9) {
            throw new DomainException('Telefon odbiorcy dla InPost musi miec 9 cyfr (polski numer bez prefiksu +48).');
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

    private function reference(Shipment $shipment): string
    {
        return OrderReferenceFormatter::format($shipment->order_id);
    }

    private function sender(Shipment $shipment): ?array
    {
        $account = $shipment->courierAccount;
        $companyName = trim((string) $account?->setting('sender_company_name'));
        $contactName = trim((string) $account?->setting('sender_contact_name'));

        if ($companyName === '' && $contactName === '') {
            return null;
        }

        [$firstName, $lastName] = $this->splitName($contactName);
        $buildingNumber = trim((string) $account->setting('sender_building_number'));
        $apartmentNumber = trim((string) $account->setting('sender_apartment_number'));

        if ($apartmentNumber !== '') {
            $buildingNumber .= '/'.$apartmentNumber;
        }

        return array_filter([
            'company_name' => $companyName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => trim((string) $account->setting('sender_email')),
            'phone' => $this->normalizePolishPhone((string) $account->setting('sender_phone')),
            'address' => array_filter([
                'street' => trim((string) $account->setting('sender_street')),
                'building_number' => $buildingNumber,
                'city' => trim((string) $account->setting('sender_city')),
                'post_code' => trim((string) $account->setting('sender_postal_code')),
                'country_code' => strtoupper(trim((string) $account->setting('sender_country_code', 'PL'))),
            ], fn (mixed $value): bool => filled($value)),
        ], fn (mixed $value): bool => filled($value));
    }
}
