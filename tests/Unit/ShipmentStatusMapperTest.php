<?php

namespace Tests\Unit;

use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShipmentStatusMapperTest extends TestCase
{
    #[DataProvider('inPostStatuses')]
    public function test_it_maps_inpost_status_to_oms_status(string $providerStatus, string $expected): void
    {
        $mapper = new ShipmentStatusMapper;

        $this->assertSame(
            $expected,
            $mapper->map(CourierAccount::PROVIDER_INPOST_LOCKERS, $providerStatus),
        );
    }

    public static function inPostStatuses(): array
    {
        return [
            'queued' => ['queued', Shipment::OMS_STATUS_CREATED],
            'confirmed' => ['confirmed', Shipment::OMS_STATUS_CREATED],
            'sender dispatched' => ['dispatched_by_sender', Shipment::OMS_STATUS_DISPATCHED],
            'sorting center' => ['adopted_at_sorting_center', Shipment::OMS_STATUS_DISPATCHED],
            'out for delivery' => ['out_for_delivery', Shipment::OMS_STATUS_OUT_FOR_DELIVERY],
            'ready for pickup' => ['ready_to_pickup', Shipment::OMS_STATUS_READY_FOR_PICKUP],
            'delivered' => ['delivered', Shipment::OMS_STATUS_DELIVERED],
            'returned' => ['returned_to_sender', Shipment::OMS_STATUS_RETURNED],
            'known creation failure' => ['creation_failed', Shipment::OMS_STATUS_PROBLEM],
            'unknown creation outcome' => ['creation_unknown', Shipment::OMS_STATUS_PROBLEM],
            'delivery failure' => ['undelivered', Shipment::OMS_STATUS_PROBLEM],
            'unknown provider status' => ['new_status_from_courier', Shipment::OMS_STATUS_PROBLEM],
        ];
    }

    public function test_progress_values_and_colors_match_the_oms_workflow(): void
    {
        $expected = [
            Shipment::OMS_STATUS_CREATED => [0, ''],
            Shipment::OMS_STATUS_DISPATCHED => [33, ''],
            Shipment::OMS_STATUS_OUT_FOR_DELIVERY => [66, ''],
            Shipment::OMS_STATUS_READY_FOR_PICKUP => [90, ''],
            Shipment::OMS_STATUS_DELIVERED => [100, 'is-success'],
            Shipment::OMS_STATUS_PROBLEM => [50, 'is-error'],
            Shipment::OMS_STATUS_RETURNED => [100, 'is-error'],
        ];

        foreach ($expected as $status => [$progress, $class]) {
            $shipment = new Shipment(['oms_status' => $status]);

            $this->assertSame($progress, $shipment->omsStatusProgress());
            $this->assertSame($class, $shipment->omsStatusProgressClass());
        }
    }

    #[DataProvider('dpdStatuses')]
    public function test_it_maps_dpd_business_codes(string $providerStatus, string $expected): void
    {
        $mapper = new ShipmentStatusMapper;

        $this->assertSame($expected, $mapper->map(CourierAccount::PROVIDER_DPD, $providerStatus));
    }

    public static function dpdStatuses(): array
    {
        return [
            'created' => ['30103', Shipment::OMS_STATUS_CREATED],
            'created with DPD padding' => ['030103', Shipment::OMS_STATUS_CREATED],
            'dispatched' => ['160101', Shipment::OMS_STATUS_DISPATCHED],
            'dispatched with DPD padding' => ['040101', Shipment::OMS_STATUS_DISPATCHED],
            'out for delivery' => ['170101', Shipment::OMS_STATUS_OUT_FOR_DELIVERY],
            'pickup point' => ['502300', Shipment::OMS_STATUS_READY_FOR_PICKUP],
            'delivered' => ['190101', Shipment::OMS_STATUS_DELIVERED],
            'returned' => ['500610', Shipment::OMS_STATUS_RETURNED],
            'unknown' => ['999999', Shipment::OMS_STATUS_PROBLEM],
        ];
    }

    #[DataProvider('allegroStatuses')]
    public function test_it_maps_allegro_tracking_statuses(string $providerStatus, string $expected): void
    {
        $mapper = new ShipmentStatusMapper;

        $this->assertSame($expected, $mapper->map(CourierAccount::PROVIDER_ALLEGRO_SHIPPING, $providerStatus));
    }

    public static function allegroStatuses(): array
    {
        return [
            'confirmed shipment' => ['confirmed', Shipment::OMS_STATUS_CREATED],
            'pending' => ['PENDING', Shipment::OMS_STATUS_CREATED],
            'in transit' => ['IN_TRANSIT', Shipment::OMS_STATUS_DISPATCHED],
            'released for delivery' => ['RELEASED_FOR_DELIVERY', Shipment::OMS_STATUS_OUT_FOR_DELIVERY],
            'available for pickup' => ['AVAILABLE_FOR_PICKUP', Shipment::OMS_STATUS_READY_FOR_PICKUP],
            'notice left' => ['NOTICE_LEFT', Shipment::OMS_STATUS_READY_FOR_PICKUP],
            'delivered' => ['DELIVERED', Shipment::OMS_STATUS_DELIVERED],
            'returned' => ['RETURNED', Shipment::OMS_STATUS_RETURNED],
            'issue' => ['ISSUE', Shipment::OMS_STATUS_PROBLEM],
        ];
    }
}
