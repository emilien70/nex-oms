<?php

namespace Modules\Integrations\AllegroShipping\Services;

use DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Modules\Integrations\AllegroShipping\Jobs\CancelAllegroShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\CreateAllegroShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\RefreshAllegroShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\ResolveAllegroCancellationJob;
use Modules\Integrations\AllegroShipping\Jobs\ResolveAllegroShipmentCommandJob;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentDeletionService;

class AllegroShippingOperations
{
    public function __construct(
        private readonly AllegroShippingClient $client,
        private readonly AllegroShippingPayloadFactory $payloadFactory,
        private readonly AllegroShippingSynchronizer $synchronizer,
        private readonly ShipmentDeletionService $deletionService,
    ) {}

    public function dispatchCreate(Shipment $shipment): void
    {
        CreateAllegroShipmentJob::dispatch($shipment)->afterCommit();
    }

    public function create(Shipment $shipment): Shipment
    {
        if (filled($shipment->external_id)) {
            return $shipment;
        }

        $shipment->loadMissing(['order', 'courierAccount', 'parcels']);
        $this->client->createCommand($shipment->courierAccount, $shipment, $this->payloadFactory->make($shipment));

        if (! $this->resolveCreation($shipment)) {
            ResolveAllegroShipmentCommandJob::dispatch($shipment)->delay(now()->addSeconds(5));
        }

        return $shipment->fresh();
    }

    public function resolveCreation(Shipment $shipment): bool
    {
        $shipment->loadMissing('courierAccount');
        $command = $this->client->createCommandStatus($shipment->courierAccount, $shipment);

        if (! $this->synchronizer->applyCommand($shipment, $command)) {
            return false;
        }

        $shipment->refresh()->loadMissing('courierAccount');
        $details = $this->client->shipment($shipment->courierAccount, $shipment);
        $this->synchronizer->applyDetails($shipment, $details);

        return true;
    }

    public function dispatchRefresh(Shipment $shipment): void
    {
        if (! $shipment->hasTerminalOmsStatus()) {
            RefreshAllegroShipmentJob::dispatch($shipment);
        }
    }

    public function refresh(Shipment $shipment): Shipment
    {
        if (blank($shipment->external_id)) {
            throw new DomainException('Przesylka nie ma jeszcze identyfikatora Allegro.');
        }

        $shipment->loadMissing('courierAccount');
        $this->synchronizer->applyDetails(
            $shipment,
            $this->client->shipment($shipment->courierAccount, $shipment),
        );

        $shipment->refresh();
        if (filled($shipment->carrier_code) && filled($shipment->tracking_number)) {
            $this->synchronizer->applyTracking(
                $shipment,
                $this->client->tracking($shipment->courierAccount, $shipment),
            );
        }

        return $shipment->fresh();
    }

    public function dispatchCancel(Shipment $shipment): void
    {
        CancelAllegroShipmentJob::dispatch($shipment);
    }

    public function cancel(Shipment $shipment): void
    {
        if (! $this->canCancel($shipment)) {
            throw new DomainException('Allegro nie pozwala anulowac tej przesylki przez API.');
        }

        $shipment->loadMissing('courierAccount');
        $commandId = (string) Str::uuid();
        $this->client->cancelCommand($shipment->courierAccount, $shipment, $commandId);

        if (! $this->resolveCancellation($shipment, $commandId)) {
            ResolveAllegroCancellationJob::dispatch($shipment, $commandId)->delay(now()->addSeconds(5));
        }
    }

    public function resolveCancellation(Shipment $shipment, string $commandId): bool
    {
        $shipment->loadMissing('courierAccount');
        $command = $this->client->cancelCommandStatus($shipment->courierAccount, $shipment, $commandId);
        $status = strtoupper((string) ($command['status'] ?? 'IN_PROGRESS'));

        if ($status === 'IN_PROGRESS') {
            return false;
        }

        if ($status === 'ERROR') {
            $message = collect((array) ($command['errors'] ?? []))
                ->map(fn (mixed $error): ?string => is_array($error) ? ($error['userMessage'] ?? $error['message'] ?? null) : null)
                ->filter()->implode(' ');
            throw new DomainException($message ?: 'Allegro odrzucilo anulowanie przesylki.');
        }

        $this->deletionService->delete($shipment);

        return true;
    }

    public function canCancel(Shipment $shipment): bool
    {
        return $shipment->canCancelViaCourier();
    }

    public function label(Shipment $shipment): Response
    {
        if (! $shipment->canDownloadLabel()) {
            throw new DomainException('Etykieta bedzie dostepna po utworzeniu przesylki przez Allegro.');
        }

        $shipment->loadMissing('courierAccount');

        return $this->client->label($shipment->courierAccount, $shipment);
    }
}
