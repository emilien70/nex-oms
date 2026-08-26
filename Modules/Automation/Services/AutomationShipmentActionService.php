<?php

namespace Modules\Automation\Services;

use DomainException;
use Modules\Automation\Models\AutomationRun;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Services\CourierDriverRegistry;

class AutomationShipmentActionService
{
    public function __construct(private readonly CourierDriverRegistry $courierDrivers) {}

    public function execute(AutomationRun $run, array $configuration): array
    {
        $provider = (string) ($configuration['provider'] ?? CourierAccount::PROVIDER_INPOST_LOCKERS);
        $account = CourierAccount::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new DomainException('Brak aktywnego konta przewoznika: '.$this->providerLabel($provider).'.');
        }

        $order = $run->order;
        $codAmount = filter_var($configuration['cod_auto'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && $order->cash_on_delivery
                ? max((float) $order->total_gross - (float) $order->paid_amount, 0)
                : 0;
        $insuranceAmount = $this->numberOrDefault(
            $configuration['insurance_amount'] ?? null,
            $account->setting('default_insurance_amount', 0),
        );

        if ($provider === CourierAccount::PROVIDER_INPOST_COURIER && $codAmount > $insuranceAmount) {
            $insuranceAmount = $codAmount;
        }

        $data = [
            'service' => $configuration['service'] ?? $account->setting('default_service'),
            'content_description' => trim((string) ($configuration['content_description'] ?? '')) ?: null,
            'cod_amount' => $codAmount,
            'insurance_amount' => $insuranceAmount,
            'additional_services' => array_values((array) ($configuration['additional_services'] ?? [])),
        ];

        if ($provider === CourierAccount::PROVIDER_INPOST_LOCKERS) {
            $targetPointId = trim((string) ($configuration['target_point_id'] ?? ''))
                ?: trim((string) $order->pickup_point_id);

            if ($targetPointId === '') {
                throw new DomainException('Zamowienie nie ma wskazanego Paczkomatu odbiorczego.');
            }

            $data += [
                'parcel_template' => $configuration['parcel_template'] ?? 'medium',
                'target_point_id' => $targetPointId,
                'sending_method' => $account->setting('sending_method', 'dispatch_order'),
            ];
        } else {
            $data['parcels'] = $this->parcels($configuration, $account, $provider);
        }

        $attempt = $this->courierDrivers
            ->forAccount($account)
            ->queueShipment($order, $account, $data);

        return [
            'shipment_attempt_id' => $attempt->id,
            'provider' => $provider,
        ];
    }

    private function parcels(array $configuration, CourierAccount $account, string $provider): array
    {
        $parcels = collect($configuration['parcels'] ?? [])
            ->filter(fn (mixed $parcel): bool => is_array($parcel))
            ->map(fn (array $parcel): array => [
                'weight' => (float) ($parcel['weight'] ?? 0),
                'length' => (float) ($parcel['length'] ?? 0),
                'width' => (float) ($parcel['width'] ?? 0),
                'height' => (float) ($parcel['height'] ?? 0),
                'is_non_standard' => $provider === CourierAccount::PROVIDER_INPOST_COURIER
                    && (bool) ($parcel['is_non_standard'] ?? false),
            ])
            ->values()
            ->all();

        if ($parcels !== []) {
            return $parcels;
        }

        return [[
            'weight' => (float) $account->setting('default_weight', 1),
            'length' => (float) $account->setting('default_length', 25),
            'width' => (float) $account->setting('default_width', 20),
            'height' => (float) $account->setting('default_height', 10),
            'is_non_standard' => false,
        ]];
    }

    private function numberOrDefault(mixed $value, mixed $default): float
    {
        return is_numeric($value) && $value !== '' ? (float) $value : (float) $default;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            CourierAccount::PROVIDER_INPOST_LOCKERS => 'InPost Paczkomaty',
            CourierAccount::PROVIDER_INPOST_COURIER => 'InPost Kurier',
            CourierAccount::PROVIDER_DPD => 'DPD',
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING => 'Wysylam z Allegro',
            default => $provider,
        };
    }
}
