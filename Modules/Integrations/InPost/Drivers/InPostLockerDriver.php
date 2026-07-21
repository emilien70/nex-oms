<?php

namespace Modules\Integrations\InPost\Drivers;

use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\InPost\Services\InPostShipmentOperations;
use Modules\Integrations\InPost\Services\InPostShipmentPayloadFactory;
use Modules\Integrations\InPost\Services\InPostShipmentServiceResolver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class InPostLockerDriver extends AbstractInPostDriver
{
    public function __construct(
        InPostShipmentOperations $operations,
        private readonly InPostShipmentPayloadFactory $payloadFactory,
        private readonly InPostShipmentServiceResolver $serviceResolver,
    ) {
        parent::__construct($operations);
    }

    public function provider(): string
    {
        return CourierAccount::PROVIDER_INPOST_LOCKERS;
    }

    public function queueShipment(Order $order, CourierAccount $account, array $data): Shipment
    {
        $this->assertAccount($account, 'InPost Paczkomaty');

        return DB::transaction(function () use ($order, $account, $data): Shipment {
            $codAmount = $data['cod_amount'] ?? null;
            $service = $this->serviceResolver->resolve($order, $data['service'] ?? null);
            $additionalServices = collect($data['additional_services'] ?? [])
                ->filter(fn (mixed $value): bool => in_array($value, [
                    Shipment::ADDITIONAL_SERVICE_WEEKEND,
                    Shipment::ADDITIONAL_SERVICE_RETURN_LABEL,
                ], true))
                ->unique()
                ->values()
                ->all();
            $sendingMethod = $data['sending_method'] ?? $account->setting('sending_method', 'dispatch_order');
            $dropoffPointId = $sendingMethod === 'parcel_locker'
                ? trim((string) $account->setting('sender_point_id'))
                : null;

            if ($sendingMethod === 'parcel_locker' && $dropoffPointId === '') {
                throw new DomainException('Uzupelnij Paczkomat nadawczy w konfiguracji konta InPost.');
            }

            if (in_array(Shipment::ADDITIONAL_SERVICE_WEEKEND, $additionalServices, true)
                && $service !== Shipment::SERVICE_INPOST_LOCKER_STANDARD) {
                throw new DomainException('Paczka w Weekend jest dostepna tylko dla standardowej uslugi Paczkomaty 24/7.');
            }

            if ($codAmount === null && $order->cash_on_delivery) {
                $codAmount = max((float) $order->total_gross - (float) $order->paid_amount, 0);
            }

            $contentDescription = trim((string) ($data['content_description'] ?? ''));

            if ($contentDescription === '') {
                $contentDescription = $this->contentDescription($order, $account);
            }

            $shipment = $order->shipments()->create([
                'courier_account_id' => $account->id,
                'provider' => $this->provider(),
                'service' => $service,
                'parcel_template' => $data['parcel_template'],
                'status' => Shipment::STATUS_QUEUED,
                'status_changed_at' => now(),
                'target_point_id' => $data['target_point_id'],
                'dropoff_point_id' => $dropoffPointId,
                'sending_method' => $sendingMethod,
                'content_description' => mb_substr($contentDescription, 0, 100),
                'cod_amount' => $codAmount,
                'insurance_amount' => $data['insurance_amount'] ?? null,
                'additional_services' => $additionalServices ?: null,
                'currency' => $order->currency ?: 'PLN',
                'label_format' => $account->setting('label_format', 'Pdf'),
                'label_type' => $account->setting('label_type', 'A6'),
                'request_uuid' => (string) Str::uuid(),
            ]);

            $shipment->events()->create([
                'event_type' => 'shipment_queued',
                'status' => Shipment::STATUS_QUEUED,
                'occurred_at' => now(),
            ]);

            $order->events()->create([
                'event_type' => 'shipment_queued',
                'title' => 'Przesylka InPost dodana do kolejki',
                'description' => 'Zlecono utworzenie przesylki InPost Paczkomaty',
                'payload' => ['shipment_id' => $shipment->id],
            ]);

            $this->dispatchCreate($shipment);

            return $shipment;
        });
    }

    protected function payload(Shipment $shipment): array
    {
        return $this->payloadFactory->make($shipment);
    }
}
