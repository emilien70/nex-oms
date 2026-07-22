<?php

namespace Modules\Integrations\InPost\Drivers;

use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\InPost\Services\InPostCourierServiceResolver;
use Modules\Integrations\InPost\Services\InPostCourierShipmentPayloadFactory;
use Modules\Integrations\InPost\Services\InPostShipmentOperations;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;
use Modules\Shipments\Services\ShipmentCreationAttemptService;

class InPostCourierDriver extends AbstractInPostDriver
{
    public function __construct(
        InPostShipmentOperations $operations,
        private readonly InPostCourierShipmentPayloadFactory $payloadFactory,
        private readonly InPostCourierServiceResolver $serviceResolver,
        private readonly ShipmentCreationAttemptService $attempts,
    ) {
        parent::__construct($operations);
    }

    public function provider(): string
    {
        return CourierAccount::PROVIDER_INPOST_COURIER;
    }

    public function queueShipment(Order $order, CourierAccount $account, array $data): ShipmentCreationAttempt
    {
        $this->assertAccount($account, 'InPost Kurier');

        $service = $this->serviceResolver->resolve($account, $data['service'] ?? null);
        $codAmount = (float) ($data['cod_amount'] ?? 0);
        $insuranceAmount = (float) ($data['insurance_amount'] ?? 0);

        if ($codAmount > 0 && $insuranceAmount < $codAmount) {
            throw new DomainException('Ubezpieczenie musi byc rowne lub wyzsze od kwoty pobrania.');
        }

        $parcels = array_values($data['parcels'] ?? []);

        if ($parcels === [] || count($parcels) > 99) {
            throw new DomainException('Przesylka kurierska musi zawierac od 1 do 99 paczek.');
        }

        return DB::transaction(function () use ($order, $account, $data, $service, $codAmount, $insuranceAmount, $parcels): ShipmentCreationAttempt {
            $attempt = $this->attempts->begin($order, $account, $data);
            $contentDescription = trim((string) ($data['content_description'] ?? ''));

            if ($contentDescription === '') {
                $contentDescription = $this->contentDescription($order, $account);
            }

            $additionalServices = collect($data['additional_services'] ?? [])
                ->filter(fn (mixed $value): bool => in_array($value, [
                    Shipment::ADDITIONAL_SERVICE_SMS,
                    Shipment::ADDITIONAL_SERVICE_EMAIL,
                    Shipment::ADDITIONAL_SERVICE_SATURDAY,
                    Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
                ], true))
                ->unique()
                ->values()
                ->all();

            $shipment = $order->shipments()->create([
                'courier_account_id' => $account->id,
                'provider' => $this->provider(),
                'service' => $service,
                'parcel_template' => null,
                'status' => Shipment::STATUS_QUEUED,
                'status_changed_at' => now(),
                'sending_method' => 'dispatch_order',
                'content_description' => mb_substr($contentDescription, 0, 100),
                'cod_amount' => $codAmount > 0 ? $codAmount : null,
                'insurance_amount' => $insuranceAmount > 0 ? $insuranceAmount : null,
                'additional_services' => $additionalServices ?: null,
                'currency' => $order->currency ?: 'PLN',
                'label_format' => $account->setting('label_format', 'Pdf'),
                'label_type' => $account->setting('label_type', 'A6'),
                'request_uuid' => $attempt->request_uuid,
            ]);

            foreach ($parcels as $index => $parcel) {
                $shipment->parcels()->create([
                    'position' => $index + 1,
                    'weight' => $parcel['weight'],
                    'length' => $parcel['length'],
                    'width' => $parcel['width'],
                    'height' => $parcel['height'],
                    'is_non_standard' => (bool) ($parcel['is_non_standard'] ?? false),
                ]);
            }

            $attempt = $this->attempts->attach($attempt, $shipment);

            $shipment->events()->create([
                'event_type' => 'shipment_queued',
                'status' => Shipment::STATUS_QUEUED,
                'payload' => ['parcel_count' => count($parcels)],
                'occurred_at' => now(),
            ]);

            $order->events()->create([
                'event_type' => 'shipment_queued',
                'title' => 'Przesylka InPost Kurier dodana do kolejki',
                'description' => 'Zlecono utworzenie przesylki InPost Kurier',
                'payload' => ['shipment_attempt_id' => $attempt->id, 'parcel_count' => count($parcels)],
            ]);

            $this->dispatchCreate($shipment);

            return $attempt;
        });
    }

    protected function payload(Shipment $shipment): array
    {
        return $this->payloadFactory->make($shipment);
    }
}
