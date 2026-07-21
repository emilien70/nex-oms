<?php

namespace Modules\Integrations\AllegroShipping\Services;

use App\Models\Order;
use DomainException;
use Modules\Shipments\Models\CourierAccount;

class AllegroShippingProposalService
{
    public function __construct(private readonly AllegroShippingClient $client) {}

    public function forOrder(Order $order, CourierAccount $account): array
    {
        if ($order->source !== 'allegro' || blank($order->external_id)) {
            throw new DomainException('Wysylam z Allegro jest dostepne tylko dla zamowien Allegro z numerem transakcji.');
        }

        $proposal = $this->client->deliveryProposal($account, (string) $order->external_id);

        if (! is_array($proposal['suggestedInput'] ?? null)) {
            throw new DomainException('Allegro nie zwrocilo proponowanych danych przesylki dla tego zamowienia.');
        }

        return $proposal;
    }

    public function formData(Order $order, CourierAccount $account): array
    {
        $proposal = $this->forOrder($order, $account);
        $input = (array) $proposal['suggestedInput'];
        $configuredDimensions = [
            'weight' => $account->setting('default_weight', 1),
            'length' => $account->setting('default_length', 25),
            'width' => $account->setting('default_width', 20),
            'height' => $account->setting('default_height', 10),
        ];
        $packages = collect((array) ($input['packages'] ?? []))
            ->map(fn (array $package): array => $configuredDimensions + [
                'type' => $package['type'] ?? 'PACKAGE',
            ])
            ->values()
            ->all();

        if ($packages === []) {
            $packages[] = $configuredDimensions + ['type' => 'PACKAGE'];
        }

        $availableServices = collect((array) data_get($proposal, 'deliveryOptions.0.additionalServices', []))
            ->mapWithKeys(fn (array $service): array => [(string) ($service['id'] ?? '') => (string) ($service['name'] ?? $service['id'] ?? '')])
            ->filter(fn (string $label, string $id): bool => $id !== '')
            ->all();
        $packageTypeLabels = [
            'PACKAGE' => 'Paczka',
            'DOX' => 'Dokumenty',
            'PALLET' => 'Paleta',
        ];
        $availablePackageTypes = collect((array) ($proposal['deliveryOptions'] ?? []))
            ->pluck('packageType')
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $type): array => [$type => $packageTypeLabels[$type] ?? $type])
            ->all();
        $suggestedPackageType = (string) data_get($input, 'packages.0.type', array_key_first($availablePackageTypes) ?: 'PACKAGE');

        return [
            'proposal' => $proposal,
            'parcels' => $packages,
            'cod_amount' => data_get($input, 'cashOnDelivery.amount', ''),
            'insurance_amount' => '',
            'content_description' => $this->configuredText(
                $order,
                (string) $account->setting('content_description_source', 'order_id'),
            ),
            'reference_number' => $this->configuredText(
                $order,
                (string) $account->setting('reference_number_source', 'order_id'),
            ),
            'package_type' => $suggestedPackageType,
            'available_package_types' => $availablePackageTypes ?: [$suggestedPackageType => $packageTypeLabels[$suggestedPackageType] ?? $suggestedPackageType],
            'swap_sender_receiver' => false,
            'label_format' => strtoupper((string) ($input['labelFormat'] ?? $account->setting('label_format', 'PDF'))),
            'available_additional_services' => $availableServices,
            'additional_services' => array_values(array_filter((array) ($input['additionalServices'] ?? []), 'is_string')),
            'delivery_type' => (string) data_get($proposal, 'deliveryOptions.0.deliveryType', ''),
            'carrier' => (string) data_get($proposal, 'deliveryOptions.0.carrierId', ''),
        ];
    }

    private function configuredText(Order $order, string $source): string
    {
        $value = match ($source) {
            'external_id' => $order->external_id,
            'customer_login' => $order->customer_login,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            default => $order->id,
        };

        $value = trim((string) $value);

        return mb_substr($value !== '' ? $value : (string) $order->id, 0, 100);
    }
}
