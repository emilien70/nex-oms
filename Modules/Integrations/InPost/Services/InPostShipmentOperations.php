<?php

namespace Modules\Integrations\InPost\Services;

use DomainException;
use Illuminate\Http\Client\Response;
use Modules\Integrations\InPost\Jobs\CancelInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\CreateInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentDeletionService;

class InPostShipmentOperations
{
    public function __construct(
        private readonly InPostClient $client,
        private readonly InPostShipmentSynchronizer $synchronizer,
        private readonly ShipmentDeletionService $deletionService,
    ) {}

    public function dispatchCreate(Shipment $shipment): void
    {
        CreateInPostShipmentJob::dispatch($shipment)->afterCommit();
    }

    public function dispatchRefresh(Shipment $shipment): void
    {
        if (! $shipment->hasTerminalOmsStatus()) {
            RefreshInPostShipmentJob::dispatch($shipment);
        }
    }

    public function dispatchCancel(Shipment $shipment): void
    {
        CancelInPostShipmentJob::dispatch($shipment);
    }

    public function create(Shipment $shipment, array $payload): Shipment
    {
        if (filled($shipment->external_id)) {
            return $shipment;
        }

        $response = $this->client->createShipment($shipment->courierAccount, $shipment, $payload);
        $this->synchronizer->apply($shipment, $response, 'shipment_created');

        if (! $shipment->tracking_number) {
            RefreshInPostShipmentJob::dispatch($shipment)->delay(now()->addSeconds(8));
        }

        return $shipment;
    }

    public function refresh(Shipment $shipment): Shipment
    {
        if ($shipment->hasTerminalOmsStatus()) {
            return $shipment;
        }

        $shipment->loadMissing(['order', 'courierAccount']);

        if (! $shipment->external_id) {
            throw new DomainException('Przesylka nie ma jeszcze identyfikatora InPost.');
        }

        $response = $this->client->getShipment($shipment->courierAccount, $shipment);
        $this->synchronizer->apply($shipment, $response, 'shipment_refreshed');

        return $shipment;
    }

    public function cancel(Shipment $shipment): void
    {
        $shipment->loadMissing(['order', 'courierAccount']);

        if (! $shipment->canCancelViaCourier()) {
            throw new DomainException('InPost pozwala anulowac tylko przesylke przed jej potwierdzeniem.');
        }

        $this->client->cancelShipment($shipment->courierAccount, $shipment);
        $this->deletionService->delete($shipment);
    }

    public function canCancel(Shipment $shipment): bool
    {
        return $shipment->canCancelViaCourier();
    }

    public function label(Shipment $shipment): Response
    {
        $shipment->loadMissing('courierAccount');

        if (! $shipment->canDownloadLabel()) {
            throw new DomainException('Etykieta bedzie dostepna po potwierdzeniu przesylki przez InPost.');
        }

        return $this->client->getLabel($shipment->courierAccount, $shipment);
    }

    public function trackingUrl(Shipment $shipment): ?string
    {
        if (blank($shipment->tracking_number)) {
            return null;
        }

        return 'https://inpost.pl/sledzenie-przesylek?number='.rawurlencode($shipment->tracking_number);
    }
}
