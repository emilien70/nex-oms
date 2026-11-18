<?php

namespace Modules\Integrations\AllegroShipping\Services;

use App\Models\Order;
use App\Support\AddressLineFormatter;
use DomainException;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\OrderReferenceFormatter;

class AllegroShippingPayloadFactory
{
    public function __construct(private readonly AllegroShippingProposalService $proposals) {}

    public function make(Shipment $shipment): array
    {
        $shipment->loadMissing(['order', 'courierAccount', 'parcels']);
        $proposal = $this->proposals->forOrder($shipment->order, $shipment->courierAccount);
        $suggested = (array) ($proposal['suggestedInput'] ?? []);
        $referenceNumber = trim((string) ($shipment->reference_number ?: $shipment->order_id));
        $contentDescription = trim((string) $shipment->content_description);
        $defaultDescriptions = [
            $referenceNumber,
            (string) $shipment->order_id,
            OrderReferenceFormatter::format($shipment->order_id),
        ];
        $textOnLabel = filled($contentDescription) && ! in_array($contentDescription, $defaultDescriptions, true)
            ? mb_substr($contentDescription, 0, 30)
            : null;

        $packages = $shipment->parcels->values()->map(function ($parcel) use ($textOnLabel): array {
            $package = [
                'type' => $parcel->package_type ?: 'PACKAGE',
                'length' => ['value' => (float) $parcel->length, 'unit' => 'CENTIMETER'],
                'width' => ['value' => (float) $parcel->width, 'unit' => 'CENTIMETER'],
                'height' => ['value' => (float) $parcel->height, 'unit' => 'CENTIMETER'],
                'weight' => ['value' => (float) $parcel->weight, 'unit' => 'KILOGRAMS'],
            ];

            if ($textOnLabel !== null) {
                $package['textOnLabel'] = $textOnLabel;
            }

            return $package;
        })->all();

        if ($packages === []) {
            throw new DomainException('Dodaj co najmniej jedna paczke do przesylki Allegro.');
        }

        $sender = $suggested['sender'] ?? null;
        if (! is_array($sender) || ! is_array($suggested['receiver'] ?? null)) {
            throw new DomainException('Allegro nie zwrocilo kompletnych danych nadawcy lub odbiorcy.');
        }

        $receiver = $this->receiver($shipment->order, $suggested['receiver'], $proposal);
        if ($shipment->swap_sender_receiver) {
            [$sender, $receiver] = [$receiver, $sender];
        }

        $input = [
            'sender' => $sender,
            'receiver' => $receiver,
            'referenceNumber' => $referenceNumber,
            'packages' => $packages,
            'labelFormat' => strtoupper($shipment->label_format ?: 'PDF'),
            'additionalServices' => array_values($shipment->additional_services ?? []),
            'additionalProperties' => (object) ($suggested['additionalProperties'] ?? []),
        ];

        if ((float) $shipment->insurance_amount > 0) {
            $input['insurance'] = [
                'amount' => number_format((float) $shipment->insurance_amount, 2, '.', ''),
                'currency' => $shipment->currency ?: 'PLN',
            ];
        }

        if ((float) $shipment->cod_amount > 0) {
            $input['cashOnDelivery'] = array_filter([
                'amount' => number_format((float) $shipment->cod_amount, 2, '.', ''),
                'currency' => $shipment->currency ?: 'PLN',
                'ownerName' => data_get($suggested, 'cashOnDelivery.ownerName'),
                'iban' => data_get($suggested, 'cashOnDelivery.iban'),
            ], fn (mixed $value): bool => filled($value));
        }

        return ['commandId' => $shipment->request_uuid, 'input' => $input];
    }

    private function receiver(Order $order, array $suggested, array $proposal): array
    {
        $address = $order->shippingAddressData();
        $point = trim((string) $order->pickupPointData()->id);
        $suggestedPoint = trim((string) ($suggested['point'] ?? ''));
        $deliveryType = strtoupper((string) data_get($proposal, 'deliveryOptions.0.deliveryType', ''));
        $toPoint = match ($deliveryType) {
            'APM', 'PUDO', 'TO-POINT' => true,
            'DOOR', 'TO-DOOR' => false,
            default => $suggestedPoint !== '',
        };

        if ($toPoint && $point === '') {
            throw new DomainException('Brakuje identyfikatora punktu odbioru. Uzupełnij „Odbiór w punkcie” w zamówieniu.');
        }
        if (! $toPoint && $point !== '' && ! in_array($deliveryType, ['DOOR', 'TO-DOOR'], true)) {
            throw new DomainException('Nie można potwierdzić, że wybrana metoda Allegro obsługuje zapisany punkt odbioru.');
        }

        $country = strtoupper(trim((string) $address->country_code));
        $phone = preg_replace('/[\s().-]+/', '', (string) $address->phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif ($country === 'PL' && preg_match('/^[0-9]{9}$/D', $phone)) {
            $phone = '+48'.$phone;
        }

        $receiver = [
            'name' => $address->name,
            'company' => $address->company_name,
            'street' => AddressLineFormatter::formatAddressLine($address->street, $address->building_number, $address->apartment_number),
            'postalCode' => $address->postal_code,
            'city' => $address->city,
            'state' => $address->province,
            'countryCode' => $country,
            'email' => $suggested['email'] ?? null,
            'phone' => $phone,
            'point' => $toPoint ? $point : null,
        ];
        $receiver = array_filter(array_map(fn ($value): string => trim((string) $value), $receiver),
            fn (string $value): bool => $value !== '');

        $required = $toPoint ? ['countryCode', 'phone'] : ['street', 'postalCode', 'city', 'countryCode', 'phone'];
        if (array_diff($required, array_keys($receiver)) || (! isset($receiver['name']) && ! isset($receiver['company']))) {
            throw new DomainException('Uzupełnij dane odbiorcy w adresie dostawy zamówienia przed nadaniem przesyłki.');
        }
        if (! isset($receiver['email'])) {
            throw new DomainException('Allegro nie zwróciło wymaganego maskowanego adresu e-mail odbiorcy.');
        }

        return $receiver;
    }
}
