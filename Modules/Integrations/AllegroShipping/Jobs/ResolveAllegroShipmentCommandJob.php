<?php

namespace Modules\Integrations\AllegroShipping\Jobs;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\AllegroShipping\Exceptions\AllegroShippingApiException;
use Modules\Integrations\AllegroShipping\Jobs\Concerns\UsesAllegroShippingApiMiddleware;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingOperations;
use Modules\Shipments\Jobs\Concerns\TracksShipmentConfirmation;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Support\ShipmentQueue;
use Throwable;

class ResolveAllegroShipmentCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksShipmentConfirmation, UsesAllegroShippingApiMiddleware;

    public int $tries = 240;

    public int $maxExceptions = 3;

    public array $backoff = [10, 30, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Shipment $shipment)
    {
        $this->onQueue(ShipmentQueue::ACTIONS);
        $this->confirmationStartedAt = CarbonImmutable::now()->getTimestamp();
    }

    public function handle(AllegroShippingOperations $operations): void
    {
        $shipment = $this->shipment->fresh();
        if ($this->confirmationFinished($shipment)) {
            return;
        }

        if ($this->confirmationExpired()) {
            $this->failed(null);

            return;
        }

        try {
            $result = $operations->confirmCreation($shipment);
        } catch (AllegroShippingApiException $exception) {
            if ($exception->statusCode === null || in_array($exception->statusCode, [404, 408, 429], true)
                || $exception->statusCode >= 500) {
                $this->releaseConfirmation(max(10, $exception->retryAfterSeconds ?? 0));
            } else {
                $this->failed($exception);
            }

            return;
        } catch (DomainException $exception) {
            $this->failed($exception);

            return;
        }

        if (! $result->completed && ! $this->confirmationFinished($shipment)) {
            $this->releaseConfirmation($result->retryAfter);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $shipment = $this->shipment->fresh();
        if ($this->confirmationFinished($shipment)) {
            return;
        }

        $message = 'Allegro nie potwierdzilo wyniku tworzenia przesylki w wymaganym czasie. Sprawdz panel Wysylam z Allegro przed kolejna proba.';
        if ($exception && $exception->getMessage() !== '') {
            $message .= ' '.$exception->getMessage();
        }

        app(ShipmentCreationAttemptService::class)->fail($shipment, $message, true);
    }
}
