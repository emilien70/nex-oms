<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrdersSearchTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('literalLoginSearches')]
    public function test_global_search_and_login_filter_treat_special_characters_literally(string $login, string $otherLogin): void
    {
        $order = $this->createOrder('Klient znaleziony');
        $order->update(['source' => 'allegro', 'customer_login' => $login]);
        $otherOrder = $this->createOrder('Inny klient');
        $otherOrder->update(['customer_login' => $otherLogin]);

        foreach (['q', 'login'] as $parameter) {
            $this->get(route('orders.index', [$parameter => $login]))
                ->assertOk()
                ->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$order->id]);
        }
    }

    public static function literalLoginSearches(): array
    {
        return [
            'underscore' => ['m_tomek', 'mXtomek'],
            'percent' => ['buyer%test', 'buyerXYZtest'],
            'backslash' => ['buyer\\test', 'buyertest'],
            'escape character' => ['buyer!_test', 'buyerXtest'],
            'apostrophe' => ["o'connor", 'oconnor'],
        ];
    }

    #[DataProvider('textOrderFilters')]
    public function test_text_order_filters_treat_special_characters_literally(string $filter, string $column): void
    {
        $text = 'value_50%!\\end';
        $order = $this->createOrder('Klient znaleziony');
        $order->update([$column => $text]);
        $otherOrder = $this->createOrder('Inny klient');
        $otherOrder->update([$column => 'valueX50anything!end']);

        $this->get(route('orders.index', [$filter => $text]))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$order->id]);
    }

    public static function textOrderFilters(): array
    {
        return [
            'store number' => ['store_number', 'external_id'],
            'customer name' => ['customer', 'shipping_name'],
            'email' => ['email', 'customer_email'],
            'phone' => ['phone', 'customer_phone'],
            'city' => ['city', 'shipping_city'],
            'postal code' => ['postal_code', 'shipping_postal_code'],
            'shipping method' => ['shipping_method', 'shipping_method'],
            'payment method' => ['payment_method', 'payment_method'],
            'notes' => ['notes', 'notes'],
        ];
    }

    public function test_product_filter_treats_special_characters_literally(): void
    {
        $order = $this->createOrder('Klient znaleziony');
        $otherOrder = $this->createOrder('Inny klient');

        foreach ([[$order, 'Produkt_50%!\\test'], [$otherOrder, 'ProduktX50anything!test']] as [$owner, $name]) {
            $owner->items()->create([
                'product_name' => $name,
                'quantity' => 1,
                'unit_price_gross' => '10.00',
                'total_price_gross' => '10.00',
                'currency' => 'PLN',
            ]);
        }

        $this->get(route('orders.index', ['product' => 'Produkt_50%!\\test']))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$order->id]);
    }

    #[DataProvider('trackingLocations')]
    public function test_tracking_search_handles_literal_special_characters_and_preserves_exact_lookup(bool $parcel): void
    {
        $order = $this->createOrder('Klient znaleziony');
        $otherOrder = $this->createOrder('Inny klient');
        $trackingNumber = 'SHIP-A_%!\\123';

        foreach ([[$order, $trackingNumber], [$otherOrder, 'SHIP-AXmany!123']] as [$owner, $number]) {
            $shipment = $owner->shipments()->create([
                'provider' => 'inpost_courier',
                'tracking_number' => $parcel ? 'MAIN-'.$owner->id : $number,
                'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
                'status' => Shipment::STATUS_CONFIRMED,
                'request_uuid' => (string) Str::uuid(),
            ]);

            if ($parcel) {
                $shipment->parcels()->create([
                    'position' => 1,
                    'tracking_number' => $number,
                    'weight' => 1,
                    'length' => 25,
                    'width' => 20,
                    'height' => 10,
                ]);
            }
        }

        foreach (['q', 'tracking_number'] as $parameter) {
            $this->get(route('orders.index', [$parameter => 'A_%!\\']))
                ->assertOk()
                ->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$order->id]);
        }

        $this->get(route('orders.index', ['q' => $trackingNumber]))
            ->assertRedirect(route('orders.show', $order));

        $this->getJson(route('orders.scan', ['code' => $trackingNumber]))
            ->assertOk()
            ->assertJsonPath('order_url', route('orders.show', $order));
    }

    public static function trackingLocations(): array
    {
        return ['shipment' => [false], 'parcel' => [true]];
    }

    public function test_search_preserves_partial_login_reordered_name_and_order_number_matches(): void
    {
        $order = $this->createOrder('Anna Nowak');
        $order->update(['customer_login' => 'm_tomek']);
        $this->createOrder('Inny klient');

        foreach (['tomek', 'Nowak Anna', (string) $order->id] as $search) {
            $this->get(route('orders.index', ['q' => $search]))
                ->assertOk()
                ->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$order->id]);
        }
    }

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
