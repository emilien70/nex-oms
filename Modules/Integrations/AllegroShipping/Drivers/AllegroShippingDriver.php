<?php

namespace Modules\Integrations\AllegroShipping\Drivers;

use App\Models\Order;
use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingOperations;
use Modules\Shipments\Contracts\CourierDriver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;
use Modules\Shipments\Services\ShipmentCreationAttemptService;

class AllegroShippingDriver implements CourierDriver
{
    public function __construct(
        private readonly AllegroShippingOperations $operations,
        private readonly ShipmentCreationAttemptService $attempts,
    ) {}

    public function provider(): string
    {
        return CourierAccount::PROVIDER_ALLEGRO_SHIPPING;
    }

    public function capabilities(): array
    {
        return [self::CAPABILITY_CREATE, self::CAPABILITY_REFRESH, self::CAPABILITY_LABEL, self::CAPABILITY_CANCEL, self::CAPABILITY_TRACKING];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function queueShipment(Order $order, CourierAccount $account, array $data): ShipmentCreationAttempt
    {
        if ($account->provider !== $this->provider() || ! $account->is_active || ! $account->hasCompleteCredentials()) {
            throw new DomainException('Konto Wysylam z Allegro nie jest aktywne lub nie ma tokenu OAuth.');
        }
        if ($order->source !== 'allegro' || blank($order->external_id)) {
            throw new DomainException('Przesylke przez Wysylam z Allegro mozna utworzyc tylko dla zamowienia Allegro z numerem transakcji.');
        }

        $parcels = array_values($data['parcels'] ?? []);
        if ($parcels === [] || count($parcels) > 10) {
            throw new DomainException('Przesylka Allegro musi zawierac od 1 do 10 paczek.');
        }

        return DB::transaction(function () use ($order, $account, $data, $parcels): ShipmentCreationAttempt {
            $attempt = $this->attempts->begin($order, $account, $data);
            $shipment = $order->shipments()->create([
                'courier_account_id' => $account->id,
                'provider' => $this->provider(),
                'service' => Shipment::SERVICE_ALLEGRO_DELIVERY,
                'status' => Shipment::STATUS_QUEUED,
                'oms_status' => Shipment::OMS_STATUS_CREATED,
                'status_changed_at' => now(),
                'oms_status_changed_at' => now(),
                'sending_method' => 'allegro_order',
                'content_description' => mb_substr(trim((string) ($data['content_description'] ?? $order->id)), 0, 100),
                'reference_number' => mb_substr(trim((string) ($data['reference_number'] ?? $order->id)), 0, 100),
                'swap_sender_receiver' => (bool) ($data['swap_sender_receiver'] ?? false),
                'cod_amount' => (float) ($data['cod_amount'] ?? 0) > 0 ? $data['cod_amount'] : null,
                'insurance_amount' => (float) ($data['insurance_amount'] ?? 0) > 0 ? $data['insurance_amount'] : null,
                'additional_services' => collect($data['additional_services'] ?? [])->filter()->unique()->values()->all() ?: null,
                'currency' => $order->currency ?: 'PLN',
                'label_format' => strtoupper((string) ($data['label_format'] ?? $account->setting('label_format', 'PDF'))),
                'label_type' => strtoupper((string) $account->setting('label_type', 'A6')),
                'request_uuid' => $attempt->request_uuid,
            ]);

            foreach ($parcels as $index => $parcel) {
                $shipment->parcels()->create([
                    'position' => $index + 1,
                    'package_type' => $data['package_type'] ?? 'PACKAGE',
                    'weight' => $parcel['weight'],
                    'length' => $parcel['length'],
                    'width' => $parcel['width'],
                    'height' => $parcel['height'],
                    'is_non_standard' => false,
                ]);
            }

            $attempt = $this->attempts->attach($attempt, $shipment);

            $shipment->events()->create([
                'event_type' => 'shipment_queued',
                'status' => Shipment::STATUS_QUEUED,
                'payload' => ['parcel_count' => count($parcels), 'allegro_order_id' => $order->external_id],
                'occurred_at' => now(),
            ]);
            $order->events()->create([
                'event_type' => 'shipment_queued',
                'title' => 'Przesylka Wysylam z Allegro dodana do kolejki',
                'description' => 'Rozpoczeto tworzenie przesylki dla zamowienia Allegro.',
                'payload' => ['shipment_attempt_id' => $attempt->id, 'parcel_count' => count($parcels)],
            ]);
            $this->dispatchCreate($shipment);

            return $attempt;
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
        $this->operations->dispatchCancel($shipment);
    }

    public function cancel(Shipment $shipment): void
    {
        $this->operations->cancel($shipment);
    }

    public function canCancel(Shipment $shipment): bool
    {
        return $this->operations->canCancel($shipment);
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
