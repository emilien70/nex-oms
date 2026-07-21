<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAjaxUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_state_can_render_order_fragments(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_name' => 'Produkt AJAX',
            'quantity' => 2,
            'unit_price_gross' => 25,
            'total_price_gross' => 50,
            'currency' => 'PLN',
        ]);
        $order->events()->create([
            'event_type' => 'test_event',
            'title' => 'Zdarzenie AJAX',
        ]);

        $response = $this->getJson(route('orders.state', $order).'?fragments=order-info,shipping,billing,pickup,products,history,shipments');

        $response->assertOk()->assertJsonStructure([
            'fields',
            'latest_event_id',
            'shipments_signature',
            'fragments' => [
                'order-info',
                'shipping',
                'billing',
                'pickup',
                'products',
                'shipments',
                'history',
            ],
        ]);

        $this->assertStringContainsString('Produkt AJAX', $response->json('fragments.products'));
        $this->assertStringContainsString('Zdarzenie AJAX', $response->json('fragments.history'));
    }

    public function test_inline_order_section_returns_ajax_refresh_instructions(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_name' => 'Produkt z dostawa',
            'quantity' => 2,
            'unit_price_gross' => 50,
            'total_price_gross' => 100,
            'currency' => 'PLN',
        ]);

        $this->patchJson(route('orders.sections.order-info', $order), [
            'source' => 'manual',
            'customer_login' => 'ajax-user',
            'customer_email' => 'ajax@example.test',
            'customer_phone' => '501294368',
            'shipping_method' => 'Paczkomat',
            'cash_on_delivery' => false,
            'delivery_cost_gross' => 12.50,
            'payment_method' => 'Przelew',
            'notes' => 'Zapis bez przeładowania',
        ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('refresh.0', 'order-info')
            ->assertJsonPath('refresh.1', 'history');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_login' => 'ajax-user',
            'shipping_method' => 'Paczkomat',
            'total_gross' => 112.50,
        ]);
    }

    public function test_payment_and_product_mutations_return_ajax_refresh_instructions(): void
    {
        $order = $this->order(['total_gross' => 100]);

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'order-info');

        $this->postJson(route('orders.products.store', $order), [
            'product_name' => 'Nowy produkt',
            'quantity' => 1,
            'unit_price_gross' => 49.99,
            'currency' => 'PLN',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'products')
            ->assertJsonPath('refresh.1', 'order-info')
            ->assertJsonPath('refresh.2', 'history');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Nowy produkt',
        ]);
        $this->assertSame('49.99', $order->fresh()->total_gross);

        $orderItem = $order->items()->firstOrFail();

        $this->patchJson(route('order-items.update', $orderItem), [
            'product_name' => 'Nowy produkt',
            'quantity' => 2,
            'unit_price_gross' => 30,
            'currency' => 'PLN',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.1', 'order-info');

        $this->assertSame('60.00', $order->fresh()->total_gross);

        $this->deleteJson(route('order-items.destroy', $orderItem))
            ->assertOk()
            ->assertJsonPath('refresh.1', 'order-info');

        $order->refresh();
        $this->assertSame('0.00', $order->total_gross);
        $this->assertSame('40.00', $order->paid_amount);

        $orderInfo = $this->getJson(route('orders.state', $order).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bg-danger', $orderInfo);
        $this->assertStringContainsString('40,00 PLN', $orderInfo);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 0,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'shipping_name' => 'Jan Kowalski',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '12',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
        ], $overrides));
    }
}
