<?php

namespace Modules\Integrations\DPD\Drivers;

use App\Models\Order;
use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\DPD\Services\DpdServiceResolver;
use Modules\Integrations\DPD\Services\DpdShipmentOperations;
use Modules\Shipments\Contracts\CourierDriver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class DpdDriver implements CourierDriver
{
    public function __construct(
        private readonly DpdShipmentOperations $operations,
        private readonly DpdServiceResolver $services,
    ) {}

    public function provider(): string
    {
        return CourierAccount::PROVIDER_DPD;
    }

    public function capabilities(): array
    {
        return [
            CourierDriver::CAPABILITY_CREATE,
            CourierDriver::CAPABILITY_REFRESH,
            CourierDriver::CAPABILITY_LABEL,
            CourierDriver::CAPABILITY_TRACKING,
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function queueShipment(Order $order, CourierAccount $account, array $data): Shipment
    {
        if ($account->provider !== $this->provider() || ! $account->is_active || ! $account->hasCompleteCredentials()) {
            throw new DomainException('Konto DPD nie jest aktywne lub nie ma kompletnych danych dostepowych.');
        }

        if (! in_array($data['service'], $this->services->supportedServices(), true)) {
            throw new DomainException('Wybrana usluga DPD nie jest obslugiwana.');
        }

        $parcels = array_values($data['parcels'] ?? []);
        if ($parcels === [] || count($parcels) > 100) {
            throw new DomainException('Przesylka DPD musi zawierac od 1 do 100 paczek.');
        }

        return DB::transaction(function () use ($order, $account, $data, $parcels): Shipment {
            $shipment = $order->shipments()->create([
                'courier_account_id' => $account->id,
                'provider' => $this->provider(),
                'service' => $data['service'],
                'status' => Shipment::STATUS_QUEUED,
                'oms_status' => Shipment::OMS_STATUS_CREATED,
                'status_changed_at' => now(),
                'oms_status_changed_at' => now(),
                'sending_method' => 'dispatch_order',
                'content_description' => mb_substr(trim((string) ($data['content_description'] ?? $order->id)), 0, 100),
                'cod_amount' => (float) ($data['cod_amount'] ?? 0) > 0 ? $data['cod_amount'] : null,
                'insurance_amount' => (float) ($data['insurance_amount'] ?? 0) > 0 ? $data['insurance_amount'] : null,
                'additional_services' => collect($data['additional_services'] ?? [])
                    ->filter(fn (mixed $value): bool => in_array($value, [
                        Shipment::ADDITIONAL_SERVICE_SATURDAY,
                        Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
                    ], true))
                    ->unique()->values()->all() ?: null,
                'currency' => $order->currency ?: 'PLN',
                'label_format' => $account->setting('label_format', 'PDF'),
                'label_type' => $account->setting('label_type', 'LABEL'),
                'request_uuid' => (string) Str::uuid(),
            ]);

            foreach ($parcels as $index => $parcel) {
                $shipment->parcels()->create([
                    'position' => $index + 1,
                    'weight' => $parcel['weight'],
                    'length' => $parcel['length'],
                    'width' => $parcel['width'],
                    'height' => $parcel['height'],
                    'is_non_standard' => false,
                ]);
            }

            $shipment->events()->create([
                'event_type' => 'shipment_queued',
                'status' => Shipment::STATUS_QUEUED,
                'payload' => ['parcel_count' => count($parcels)],
                'occurred_at' => now(),
            ]);

            $order->events()->create([
                'event_type' => 'shipment_queued',
                'title' => 'Przesylka DPD dodana do kolejki',
                'description' => 'Zlecono utworzenie przesylki DPD',
                'payload' => ['shipment_id' => $shipment->id, 'parcel_count' => count($parcels)],
            ]);

            $this->dispatchCreate($shipment);

            return $shipment->load('parcels');
        });
    }

    public function dispatchCreate(Shipment $shipment): void
    {
        $this->operations->dispatchCreate($shipment);
    }

    public function create(Shipment $shipment): Shipment
    {
        return $this->operations->create($shipment);
    }

    public function dispatchRefresh(Shipment $shipment): void
    {
        $this->operations->dispatchRefresh($shipment);
    }

    public function refresh(Shipment $shipment): Shipment
    {
        return $this->operations->refresh($shipment);
    }

    public function dispatchCancel(Shipment $shipment): void
    {
        throw new DomainException('DPD nie udostepnia anulowania utworzonej przesylki w DPD Services API.');
    }

    public function cancel(Shipment $shipment): void
    {
        $this->dispatchCancel($shipment);
    }

    public function canCancel(Shipment $shipment): bool
    {
        return false;
    }

    public function label(Shipment $shipment): Response
    {
        return $this->operations->label($shipment);
    }

    public function trackingUrl(Shipment $shipment): ?string
    {
        return $shipment->trackingUrl();
    }
}
