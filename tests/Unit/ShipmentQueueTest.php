<?php

namespace Tests\Unit;

use Modules\Integrations\AllegroShipping\Jobs\CancelAllegroShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\CreateAllegroShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\RefreshAllegroShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\ResolveAllegroCancellationJob;
use Modules\Integrations\AllegroShipping\Jobs\ResolveAllegroShipmentCommandJob;
use Modules\Integrations\DPD\Jobs\CreateDpdShipmentJob;
use Modules\Integrations\DPD\Jobs\RefreshDpdShipmentJob;
use Modules\Integrations\InPost\Jobs\CancelInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\CreateInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Support\ShipmentQueue;
use PHPUnit\Framework\TestCase;

class ShipmentQueueTest extends TestCase
{
    public function test_interactive_shipment_jobs_use_actions_queue(): void
    {
        $shipment = new Shipment;
        $jobs = [
            new CreateInPostShipmentJob($shipment),
            new CancelInPostShipmentJob($shipment),
            new CreateDpdShipmentJob($shipment),
            new CreateAllegroShipmentJob($shipment),
            new CancelAllegroShipmentJob($shipment),
            new ResolveAllegroShipmentCommandJob($shipment),
            new ResolveAllegroCancellationJob($shipment, 'command-id'),
        ];

        foreach ($jobs as $job) {
            $this->assertSame(ShipmentQueue::ACTIONS, $job->queue, $job::class);
        }
    }

    public function test_status_refresh_jobs_use_sync_queue(): void
    {
        $shipment = new Shipment;
        $jobs = [
            new RefreshInPostShipmentJob($shipment),
            new RefreshDpdShipmentJob($shipment),
            new RefreshAllegroShipmentJob($shipment),
        ];

        foreach ($jobs as $job) {
            $this->assertSame(ShipmentQueue::SYNC, $job->queue, $job::class);
        }
    }
}
