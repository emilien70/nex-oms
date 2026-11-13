<?php

namespace Modules\Integrations\DPD\Services;

use DomainException;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\OrderReferenceFormatter;

class DpdShipmentPayloadFactory
{
    public function __construct(private readonly DpdServiceResolver $services) {}

    public function make(Shipment $shipment): array
    {
        $shipment->loadMissing(['order', 'courierAccount', 'parcels']);

        $services = [];
        $transportCode = $this->services->transportCode($shipment->service);

        if ($transportCode) {
            $services[] = ['code' => $transportCode];
        }

        if ((float) $shipment->cod_amount > 0) {
            $services[] = $this->amountService('COD', $shipment->cod_amount, $shipment->currency);
        }

        if ((float) $shipment->insurance_amount > 0) {
            $services[] = $this->amountService('DECLARED_VALUE', $shipment->insurance_amount, $shipment->currency);
        }

        foreach ($shipment->additional_services ?? [] as $service) {
            $code = match ($service) {
                Shipment::ADDITIONAL_SERVICE_SATURDAY => 'SATURDAY',
                Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => 'ROD',
                default => null,
            };

            if ($code) {
                $services[] = ['code' => $code];
            }
        }

        $parcels = $shipment->parcels->values()->map(fn ($parcel, int $index): array => array_filter([
            'reference' => mb_substr($shipment->request_uuid.'-'.($index + 1), 0, 50),
            'weight' => (float) $parcel->weight,
            'sizeX' => (float) $parcel->length,
            'sizeY' => (float) $parcel->width,
            'sizeZ' => (float) $parcel->height,
            'content' => filled($shipment->content_description)
                ? mb_substr((string) $shipment->content_description, 0, 300)
                : null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''))->all();

        if ($parcels === []) {
            throw new DomainException('Dodaj co najmniej jedna paczke do przesylki DPD.');
        }

        return [
            'generationPolicy' => 'ALL_OR_NOTHING',
            'packages' => [[
                'reference' => mb_substr('NEX-'.$shipment->request_uuid, 0, 50),
                'receiver' => $this->receiver($shipment),
                'sender' => $this->sender($shipment),
                'payerFID' => (int) $shipment->courierAccount->resolvedOrganizationId(),
                'ref1' => OrderReferenceFormatter::format($shipment->order_id),
                'services' => $services,
                'parcels' => $parcels,
            ]],
        ];
    }

    public function label(Shipment $shipment): array
    {
        $shipment->loadMissing('parcels');
        $waybills = $shipment->parcels->pluck('tracking_number')->filter()->values();

        if ($waybills->isEmpty() && filled($shipment->tracking_number)) {
            $waybills = collect([$shipment->tracking_number]);
        }

        if ($waybills->isEmpty()) {
            throw new DomainException('Przesylka DPD nie ma numeru nadawczego potrzebnego do etykiety.');
        }

        return [
            'labelSearchParams' => [
                'policy' => 'STOP_ON_FIRST_ERROR',
                'session' => [
                    'packages' => [[
                        'parcels' => $waybills->map(fn (string $waybill): array => ['waybill' => $waybill])->all(),
                    ]],
                    'type' => $this->labelSessionType($shipment),
                ],
            ],
            'outputDocFormat' => strtoupper($shipment->label_format ?: 'PDF'),
            'format' => strtoupper($shipment->label_type ?: 'LABEL') === 'A4' ? 'A4' : 'LBL_PRINTER',
            'outputType' => 'BIC3',
            'variant' => 'STANDARD',
        ];
    }

    private function labelSessionType(Shipment $shipment): string
    {
        $created = $shipment->events()
            ->where('event_type', 'shipment_created')
            ->latest('id')
            ->first(['payload']);
        $sender = data_get($created?->payload, 'sender_country_code');
        $receiver = data_get($created?->payload, 'receiver_country_code');

        if (blank($sender) || blank($receiver)) {
            // Older shipments did not record the countries in the creation event.
            $creation = $shipment->apiLogs()
                ->where('integration', $shipment->provider)
                ->where('operation', 'create_shipment')
                ->where('successful', true)
                ->latest('id')
                ->first(['request_payload']);
            $sender = data_get($creation?->request_payload, 'packages.0.sender.countryCode');
            $receiver = data_get($creation?->request_payload, 'packages.0.receiver.countryCode');
        }

        if (blank($sender) || blank($receiver)) {
            $shipment->loadMissing(['order', 'courierAccount']);
            $sender = $shipment->courierAccount?->setting('sender_country_code', 'PL') ?: 'PL';
            $receiver = $shipment->order?->shipping_country_code ?: 'PL';
        }

        return strtoupper(trim($sender)) === strtoupper(trim($receiver)) ? 'DOMESTIC' : 'INTERNATIONAL';
    }

    private function receiver(Shipment $shipment): array
    {
        $order = $shipment->order;

        return $this->party([
            'company' => $order->shipping_company_name,
            'name' => $order->shipping_name,
            'street' => $order->shipping_street,
            'building' => $order->shipping_building_number,
            'apartment' => $order->shipping_apartment_number,
            'city' => $order->shipping_city,
            'country' => $order->shipping_country_code ?: 'PL',
            'postal_code' => $order->shipping_postal_code,
            'phone' => $order->customer_phone,
            'email' => $order->customer_email,
        ], 'odbiorcy');
    }

    private function sender(Shipment $shipment): array
    {
        $account = $shipment->courierAccount;

        return $this->party([
            'company' => $account->setting('sender_company_name'),
            'name' => $account->setting('sender_contact_name'),
            'street' => $account->setting('sender_street'),
            'building' => $account->setting('sender_building_number'),
            'apartment' => $account->setting('sender_apartment_number'),
            'city' => $account->setting('sender_city'),
            'country' => $account->setting('sender_country_code', 'PL'),
            'postal_code' => $account->setting('sender_postal_code'),
            'phone' => $account->setting('sender_phone'),
            'email' => $account->setting('sender_email'),
        ], 'nadawcy');
    }

    private function party(array $data, string $owner): array
    {
        foreach (['street', 'building', 'city', 'postal_code'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw new DomainException('Uzupelnij pelny adres '.$owner.' przed nadaniem przesylki DPD.');
            }
        }

        if (blank($data['name'] ?? null) && blank($data['company'] ?? null)) {
            throw new DomainException('Uzupelnij imie i nazwisko lub firme '.$owner.'.');
        }

        $address = trim((string) $data['street']).' '.trim((string) $data['building']);
        if (filled($data['apartment'] ?? null)) {
            $address .= '/'.trim((string) $data['apartment']);
        }

        return array_filter([
            'company' => mb_substr(trim((string) ($data['company'] ?? '')), 0, 100),
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 100),
            'address' => mb_substr(trim($address), 0, 100),
            'city' => mb_substr(trim((string) $data['city']), 0, 50),
            'countryCode' => strtoupper(trim((string) ($data['country'] ?? 'PL'))),
            'postalCode' => preg_replace('/\W+/', '', (string) $data['postal_code']) ?: $data['postal_code'],
            'phone' => preg_replace('/\D+/', '', (string) ($data['phone'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
        ], fn (mixed $value): bool => filled($value));
    }

    private function amountService(string $code, mixed $amount, string $currency): array
    {
        return [
            'code' => $code,
            'attributes' => [
                ['code' => 'AMOUNT', 'value' => number_format((float) $amount, 2, '.', '')],
                ['code' => 'CURRENCY', 'value' => strtoupper($currency ?: 'PLN')],
            ],
        ];
    }
}
