<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAddressIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_address_edit_does_not_update_other_orders(): void
    {
        $firstOrder = Order::create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'shipping_name' => 'Anna Kowalska',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '12',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
            'currency' => 'PLN',
        ]);

        $secondOrder = Order::create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'shipping_name' => 'Anna Kowalska',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '12',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
            'currency' => 'PLN',
        ]);

        $this->patch(route('orders.sections.shipping-address', $firstOrder), [
            'shipping_name' => 'Jan Nowak',
            'shipping_company_name' => '',
            'shipping_address_line' => 'Nowa 7',
            'shipping_postal_code' => '11-111',
            'shipping_city' => 'Krakow',
            'shipping_country_code' => 'PL',
        ])->assertRedirect();

        $firstOrder->refresh();
        $secondOrder->refresh();

        $this->assertSame('Jan Nowak', $firstOrder->shipping_name);
        $this->assertSame('Nowa', $firstOrder->shipping_street);
        $this->assertSame('Anna Kowalska', $secondOrder->shipping_name);
        $this->assertSame('Testowa', $secondOrder->shipping_street);
    }
}
