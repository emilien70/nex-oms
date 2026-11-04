<?php

namespace Modules\Shipments\Jobs\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\ShipmentConfirmationSchedule;

trait TracksShipmentConfirmation
{
    // Nullable for jobs already serialized before confirmation deadlines existed.
    public ?int $confirmationStartedAt = null;

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestampUTC($this->confirmationStart() + ShipmentConfirmationSchedule::TIMEOUT_SECONDS);
    }

    private function confirmationStart(): int
    {
        return $this->confirmationStartedAt ??= $this->shipment->created_at?->getTimestamp()
            ?? CarbonImmutable::now()->getTimestamp();
    }

    private function confirmationExpired(): bool
    {
        return CarbonImmutable::now()->getTimestamp() >= $this->retryUntil()->getTimestamp();
    }

    private function confirmationFinished(?Shipment $shipment): bool
    {
        return ! $shipment || filled($shipment->tracking_number) || $shipment->hasTerminalOmsStatus()
            || in_array($shipment->status, [Shipment::STATUS_CANCELLED, Shipment::STATUS_CANCELLED_LOCALLY,
                'canceled', Shipment::STATUS_CREATION_FAILED, Shipment::STATUS_CREATION_UNKNOWN], true);
    }

    private function releaseConfirmation(?int $retryAfter = null): void
    {
        $remaining = max(1, $this->retryUntil()->getTimestamp() - CarbonImmutable::now()->getTimestamp());
        $this->release(min($remaining, ShipmentConfirmationSchedule::delay($this->confirmationStart(), $retryAfter)));
    }
}
