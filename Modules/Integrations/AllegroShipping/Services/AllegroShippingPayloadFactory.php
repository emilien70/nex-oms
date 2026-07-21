<?php

namespace Modules\Integrations\AllegroShipping\Services;

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
        $receiver = $suggested['receiver'] ?? null;
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

        if (! is_array($input['sender']) || ! is_array($input['receiver'])) {
            throw new DomainException('Allegro nie zwrocilo kompletnych danych nadawcy lub odbiorcy.');
        }

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
}
