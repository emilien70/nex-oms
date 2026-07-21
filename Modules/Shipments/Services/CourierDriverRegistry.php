<?php

namespace Modules\Shipments\Services;

use DomainException;
use Modules\Shipments\Contracts\CourierDriver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

class CourierDriverRegistry
{
    /** @var array<string, CourierDriver> */
    private array $drivers = [];

    /** @param iterable<CourierDriver> $drivers */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $provider => $driver) {
            $this->drivers[is_string($provider) ? $provider : $driver->provider()] = $driver;
        }
    }

    public function driver(string $provider): CourierDriver
    {
        return $this->drivers[$provider]
            ?? throw new DomainException('Brak sterownika dla integracji kurierskiej: '.$provider.'.');
    }

    public function forAccount(CourierAccount $account): CourierDriver
    {
        return $this->driver($account->provider);
    }

    public function forShipment(Shipment $shipment): CourierDriver
    {
        return $this->driver($shipment->provider);
    }

    public function supports(string $provider, string $capability): bool
    {
        return isset($this->drivers[$provider]) && $this->drivers[$provider]->supports($capability);
    }
}
