<?php

namespace Modules\Integrations\InPost\Drivers;

use App\Models\Order;
use DomainException;
use Illuminate\Http\Client\Response;
use Modules\Integrations\InPost\Services\InPostShipmentOperations;
use Modules\Shipments\Contracts\CourierDriver;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\OrderReferenceFormatter;

abstract class AbstractInPostDriver implements CourierDriver
{
    public function __construct(
        protected readonly InPostShipmentOperations $operations,
    ) {}

    public function capabilities(): array
    {
        return [
            CourierDriver::CAPABILITY_CREATE,
            CourierDriver::CAPABILITY_REFRESH,
            CourierDriver::CAPABILITY_LABEL,
            CourierDriver::CAPABILITY_CANCEL,
            CourierDriver::CAPABILITY_TRACKING,
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function dispatchCreate(Shipment $shipment): void
    {
        $this->operations->dispatchCreate($shipment);
    }

    public function create(Shipment $shipment): Shipment
    {
        $shipment->loadMissing(['order', 'courierAccount']);

        if (filled($shipment->external_id)) {
            return $shipment;
        }

        return $this->operations->create($shipment, $this->payload($shipment));
    }

    public function dispatchRefresh(Shipment $shipment): void
    {
        $this->operations->dispatchRefresh($shipment);
    }

    public function refresh(Shipment $shipment): Shipment
    {
        return $this->operations->refresh($shipment);
    }

    public function dispatchCancel(Shipment $shipment): void
    {
        $this->operations->dispatchCancel($shipment);
    }

    public function cancel(Shipment $shipment): void
    {
        $this->operations->cancel($shipment);
    }

    public function canCancel(Shipment $shipment): bool
    {
        return $this->operations->canCancel($shipment);
    }

    public function label(Shipment $shipment): Response
    {
        return $this->operations->label($shipment);
    }

    public function trackingUrl(Shipment $shipment): ?string
    {
        return $this->operations->trackingUrl($shipment);
    }

    abstract protected function payload(Shipment $shipment): array;

    protected function assertAccount(CourierAccount $account, string $label): void
    {
        if ($account->provider !== $this->provider()) {
            throw new DomainException('Konto kurierskie nie pasuje do sterownika '.$label.'.');
        }

        if (! $account->is_active || ! $account->hasCompleteCredentials()) {
            throw new DomainException('Konto '.$label.' nie jest aktywne lub nie ma danych dostepowych.');
        }
    }

    protected function contentDescription(Order $order, CourierAccount $account): string
    {
        $description = match ($account->setting('content_description_source', 'order_id')) {
            'customer_login' => $order->customer_login,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            default => OrderReferenceFormatter::format($order->id),
        };

        $description = trim((string) $description);

        return mb_substr(
            $description !== '' ? $description : OrderReferenceFormatter::format($order->id),
            0,
            100,
        );
    }
}
