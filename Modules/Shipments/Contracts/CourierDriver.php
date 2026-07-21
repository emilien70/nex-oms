<?php

namespace Modules\Shipments\Contracts;

use App\Models\Order;
use Illuminate\Http\Client\Response;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;

interface CourierDriver
{
    public const CAPABILITY_CREATE = 'create';

    public const CAPABILITY_REFRESH = 'refresh';

    public const CAPABILITY_LABEL = 'label';

    public const CAPABILITY_CANCEL = 'cancel';

    public const CAPABILITY_TRACKING = 'tracking';

    public function provider(): string;

    public function capabilities(): array;

    public function supports(string $capability): bool;

    public function queueShipment(Order $order, CourierAccount $account, array $data): Shipment;

    public function dispatchCreate(Shipment $shipment): void;

    public function create(Shipment $shipment): Shipment;

    public function dispatchRefresh(Shipment $shipment): void;

    public function refresh(Shipment $shipment): Shipment;

    public function dispatchCancel(Shipment $shipment): void;

    public function cancel(Shipment $shipment): void;

    public function canCancel(Shipment $shipment): bool;

    public function label(Shipment $shipment): Response;

    public function trackingUrl(Shipment $shipment): ?string;
}
