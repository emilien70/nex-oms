<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;
use Tests\TestCase;

class OrdersSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_can_be_searched_by_shipment_or_parcel_tracking_number(): void
    {
        $order = $this->createOrder('Zamowienie z przesylka');
        $otherOrder = $this->createOrder('Inne zamowienie');
        $shipment = $order->shipments()->create([
            'provider' => 'inpost_courier',
            'tracking_number' => 'MAIN-TRACKING-123',
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'request_uuid' => (string) Str::uuid(),
        ]);
        $shipment->parcels()->create([
            'position' => 1,
            'tracking_number' => 'PARCEL-TRACKING-456',
            'weight' => 1,
            'length' => 25,
            'width' => 20,
            'height' => 10,
        ]);

        $this->get(route('orders.index', ['q' => 'TRACKING-123']))
            ->assertOk()
            ->assertSee(route('orders.show', $order), false)
            ->assertDontSee(route('orders.show', $otherOrder), false);

        $this->get(route('orders.index', ['q' => 'TRACKING-456']))
            ->assertOk()
            ->assertSee(route('orders.show', $order), false)
            ->assertDontSee(route('orders.show', $otherOrder), false);
    }

    public function test_advanced_tracking_number_filter_uses_shipment_numbers(): void
    {
        $order = $this->createOrder('Zamowienie filtrowane');
        $otherOrder = $this->createOrder('Inne zamowienie');
        $order->shipments()->create([
            'provider' => 'dpd',
            'tracking_number' => 'DPD-000123456',
            'service' => Shipment::SERVICE_DPD_DOMESTIC,
            'status' => Shipment::STATUS_CONFIRMED,
            'request_uuid' => (string) Str::uuid(),
        ]);

        $this->get(route('orders.index', ['tracking_number' => '000123456']))
            ->assertOk()
            ->assertSee(route('orders.show', $order), false)
            ->assertDontSee(route('orders.show', $otherOrder), false);
    }

    public function test_exact_tracking_number_opens_the_matching_order(): void
    {
        $order = $this->createOrder('Zamowienie otwierane');
        $shipment = $order->shipments()->create([
            'provider' => 'inpost_courier',
            'tracking_number' => 'EXACT-MAIN-123',
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'request_uuid' => (string) Str::uuid(),
        ]);
        $shipment->parcels()->create([
            'position' => 1,
            'tracking_number' => 'EXACT-PARCEL-456',
            'weight' => 1,
            'length' => 25,
            'width' => 20,
            'height' => 10,
        ]);

        $this->get(route('orders.index', ['q' => 'EXACT-MAIN-123']))
            ->assertRedirect(route('orders.show', $order));

        $this->get(route('orders.index', ['q' => 'EXACT-PARCEL-456']))
            ->assertRedirect(route('orders.show', $order));
    }

    public function test_search_switches_from_a_status_to_all_orders(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_PENDING,
            'shipping_name' => 'Globalne wyszukiwanie',
            'currency' => 'PLN',
        ]);

        $this->get(route('orders.index', [
            'status' => Order::STATUS_NEW,
            'q' => 'Globalne wyszukiwanie',
        ]))
            ->assertOk()
            ->assertSee('Wszystkie zam&oacute;wienia', false)
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_scanner_lookup_finds_main_and_parcel_tracking_numbers(): void
    {
        $order = $this->createOrder('Zamowienie ze skanera');
        $shipment = $order->shipments()->create([
            'provider' => 'inpost_courier',
            'tracking_number' => 'SCAN-MAIN-123456',
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'request_uuid' => (string) Str::uuid(),
        ]);
        $shipment->parcels()->create([
            'position' => 1,
            'tracking_number' => 'SCAN-PARCEL-654321',
            'weight' => 1,
            'length' => 25,
            'width' => 20,
            'height' => 10,
        ]);

        $this->getJson(route('orders.scan', ['code' => 'SCAN-MAIN-123456']))
            ->assertOk()
            ->assertJsonPath('order_url', route('orders.show', $order));

        $this->getJson(route('orders.scan', ['code' => 'SCAN-PARCEL-654321']))
            ->assertOk()
            ->assertJsonPath('order_url', route('orders.show', $order));
    }

    public function test_scanner_lookup_returns_an_error_when_tracking_number_is_unknown(): void
    {
        $this->getJson(route('orders.scan', ['code' => 'UNKNOWN-TRACKING-123']))
            ->assertNotFound()
            ->assertJsonPath(
                'message',
                'Nie znaleziono zamówienia dla numeru przesyłki: UNKNOWN-TRACKING-123.'
            );
    }

    public function test_scanner_lookup_does_not_open_an_ambiguous_tracking_number(): void
    {
        foreach (['Pierwsze zamowienie', 'Drugie zamowienie'] as $shippingName) {
            $this->createOrder($shippingName)->shipments()->create([
                'provider' => 'dpd',
                'tracking_number' => 'DUPLICATE-TRACKING-123',
                'service' => Shipment::SERVICE_DPD_DOMESTIC,
                'status' => Shipment::STATUS_CONFIRMED,
                'request_uuid' => (string) Str::uuid(),
            ]);
        }

        $this->getJson(route('orders.scan', ['code' => 'DUPLICATE-TRACKING-123']))
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Numer przesyłki jest przypisany do więcej niż jednego zamówienia.'
            );
    }

    public function test_global_order_search_is_visible_on_every_page_without_duplication(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-global-order-search-form', false);

        $ordersResponse = $this->get(route('orders.index'));

        $ordersResponse->assertOk();
        $this->assertSame(1, substr_count($ordersResponse->getContent(), 'data-global-order-search-form'));
    }

    private function createOrder(string $shippingName): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'shipping_name' => $shippingName,
            'currency' => 'PLN',
        ]);
    }
}
