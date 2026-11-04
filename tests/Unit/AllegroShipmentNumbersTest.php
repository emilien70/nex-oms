<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Modules\Integrations\Allegro\Support\AllegroShipmentNumbers as Numbers;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentParcel;
use PHPUnit\Framework\TestCase;

class AllegroShipmentNumbersTest extends TestCase
{
    public function test_same_waybill_keeps_primary_and_transport_carriers(): void
    {
        $pairs = Numbers::fromAllegroPackage([
            'waybill' => 'A-123',
            'transportingInfo' => [['carrierId' => 'ALLEGRO_ONE_KURIER', 'carrierWaybill' => 'A-123']],
        ], 'ALLEGRO');
        $this->assertSame(['ALLEGRO|A-123', 'ALLEGRO_ONE_KURIER|A-123'], array_keys($pairs));
    }

    public function test_different_numbers_and_carriers_are_retained_without_guessing(): void
    {
        $pairs = Numbers::fromAllegroPackage([
            'waybill' => 'A-123',
            'transportingInfo' => [
                ['carrierId' => 'INPOST', 'carrierWaybill' => '000123'],
                ['carrierId' => 'ALLEGRO', 'carrierWaybill' => 'A-123'],
                ['carrierId' => 'NEW_CARRIER', 'carrierWaybill' => 'OTHER-1'],
                ['carrierId' => 'DPD', 'carrierWaybill' => ''],
                ['carrierId' => '', 'carrierWaybill' => 'INVALID'],
            ],
        ], 'ALLEGRO');
        $this->assertSame(['ALLEGRO|A-123', 'INPOST|000123', 'NEW_CARRIER|OTHER-1'], array_keys($pairs));
    }

    public function test_current_numbers_do_not_drop_primary_carrier_when_waybill_is_shared(): void
    {
        $shipment = new Shipment(['provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING, 'carrier_code' => 'ALLEGRO', 'tracking_number' => 'A-123']);
        $shipment->setRelation('parcels', new Collection([new ShipmentParcel([
            'tracking_number' => 'A-123',
            'tracking_numbers' => [['carrier_id' => 'ALLEGRO_ONE_KURIER', 'waybill' => 'A-123']],
        ])]));
        $this->assertEqualsCanonicalizing(['ALLEGRO|A-123', 'ALLEGRO_ONE_KURIER|A-123'], array_keys(Numbers::current($shipment)));
    }

    public function test_blank_primary_number_does_not_hide_late_transport_number(): void
    {
        $this->assertSame(['INPOST|000123'], array_keys(Numbers::fromAllegroPackage([
            'waybill' => '', 'transportingInfo' => [['carrierId' => 'INPOST', 'carrierWaybill' => '000123']],
        ], 'ALLEGRO')));
    }
}
