<?php

namespace Modules\Integrations\DPD\Services;

use DomainException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Modules\Integrations\DPD\Jobs\CreateDpdShipmentJob;
use Modules\Integrations\DPD\Jobs\RefreshDpdShipmentJob;
use Modules\Shipments\Models\Shipment;

class DpdShipmentOperations
{
    public function __construct(
        private readonly DpdClient $client,
        private readonly DpdInfoServicesClient $infoClient,
        private readonly DpdShipmentPayloadFactory $payloadFactory,
        private readonly DpdShipmentSynchronizer $synchronizer,
    ) {}

    public function dispatchCreate(Shipment $shipment): void
    {
        CreateDpdShipmentJob::dispatch($shipment)->afterCommit();
    }

    public function dispatchRefresh(Shipment $shipment): void
    {
        if (! $shipment->hasTerminalOmsStatus()) {
            RefreshDpdShipmentJob::dispatch($shipment);
        }
    }

    public function create(Shipment $shipment): Shipment
    {
        if (filled($shipment->external_id)) {
            return $shipment;
        }

        $shipment->loadMissing(['order', 'courierAccount', 'parcels']);
        $data = $this->client->createShipment(
            $shipment->courierAccount,
            $shipment,
            $this->payloadFactory->make($shipment),
        );
        $this->synchronizer->applyCreated($shipment, $data);

        return $shipment->fresh();
    }

    public function refresh(Shipment $shipment): Shipment
    {
        if ($shipment->hasTerminalOmsStatus()) {
            return $shipment;
        }

        $shipment->loadMissing(['order', 'courierAccount']);

        if (blank($shipment->tracking_number)) {
            throw new DomainException('Przesylka nie ma jeszcze numeru nadawczego DPD.');
        }

        $event = $this->infoClient->latestEvent($shipment->courierAccount, $shipment);
        $this->synchronizer->applyEvent($shipment, $event);

        return $shipment->fresh();
    }

    public function label(Shipment $shipment): Response
    {
        $shipment->loadMissing(['courierAccount', 'parcels']);

        if (! $shipment->canDownloadLabel()) {
            throw new DomainException('Etykieta DPD bedzie dostepna po utworzeniu przesylki.');
        }

        $data = $this->client->getLabel(
            $shipment->courierAccount,
            $shipment,
            $this->payloadFactory->label($shipment),
        );
        $document = base64_decode((string) ($data['documentData'] ?? ''), true);

        if ($document === false || $document === '') {
            throw new DomainException('DPD nie zwrocilo poprawnej etykiety.');
        }

        $contentType = match (strtoupper($shipment->label_format ?: 'PDF')) {
            'PDF' => 'application/pdf',
            default => 'application/octet-stream',
        };

        return new Response(new Psr7Response(200, ['Content-Type' => $contentType], $document));
    }
}
