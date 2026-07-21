<?php

namespace Modules\Integrations\InPost\Services;

use DomainException;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class InPostCourierServiceResolver
{
    public function supportedServices(): array
    {
        return array_keys($this->serviceLabels());
    }

    public function serviceLabels(): array
    {
        return [
            Shipment::SERVICE_INPOST_COURIER_STANDARD => 'Przesylka kurierska standardowa',
            Shipment::SERVICE_INPOST_COURIER_EXPRESS_1000 => 'Kurier - doreczenie do 10:00',
            Shipment::SERVICE_INPOST_COURIER_EXPRESS_1200 => 'Kurier - doreczenie do 12:00',
            Shipment::SERVICE_INPOST_COURIER_EXPRESS_1700 => 'Kurier - doreczenie do 17:00',
        ];
    }

    public function resolve(CourierAccount $account, ?string $service): string
    {
        $resolved = trim((string) $service);

        if ($resolved === '') {
            $resolved = (string) $account->setting(
                'default_service',
                Shipment::SERVICE_INPOST_COURIER_STANDARD,
            );
        }

        if (! in_array($resolved, $this->supportedServices(), true)) {
            throw new DomainException('Wybrana usluga InPost Kurier nie jest obslugiwana.');
        }

        return $resolved;
    }
}
