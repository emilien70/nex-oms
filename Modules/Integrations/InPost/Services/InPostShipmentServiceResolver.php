<?php

namespace Modules\Integrations\InPost\Services;

use App\Models\Order;
use DomainException;
use Modules\Shipments\Models\Shipment;

class InPostShipmentServiceResolver
{
    public function resolve(Order $order, ?string $selectedService = null): string
    {
        $selectedService = trim((string) $selectedService);

        if ($selectedService !== '') {
            if (! in_array($selectedService, $this->supportedServices(), true)) {
                throw new DomainException('Wybrana usluga InPost nie jest obslugiwana.');
            }

            return $selectedService;
        }

        return $this->defaultFor($order);
    }

    public function defaultFor(Order $order): string
    {
        return $order->source === 'allegro'
            ? Shipment::SERVICE_INPOST_LOCKER_ALLEGRO
            : Shipment::SERVICE_INPOST_LOCKER_STANDARD;
    }

    public function supportedServices(): array
    {
        return [
            Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            Shipment::SERVICE_INPOST_LOCKER_ALLEGRO,
        ];
    }
}
