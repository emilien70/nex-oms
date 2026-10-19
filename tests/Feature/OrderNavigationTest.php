<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_navigation_uses_selected_status_and_list_order(): void
    {
        $newer = $this->order(Order::STATUS_PENDING, '2026-10-15 12:00:00');
        $differentStatus = $this->order(Order::STATUS_NEW, '2026-10-15 11:30:00');
        $current = $this->order(Order::STATUS_PENDING, '2026-10-15 11:00:00');
        $older = $this->order(Order::STATUS_PENDING, '2026-10-15 10:00:00');

        $this->get(route('orders.index', ['status' => Order::STATUS_PENDING]))
            ->assertOk()
            ->assertSee(route('orders.show', ['order' => $current, 'status' => Order::STATUS_PENDING]), false);

        $this->get(route('orders.show', ['order' => $current, 'status' => Order::STATUS_PENDING]))
            ->assertOk()
            ->assertSee('href="'.route('orders.show', ['order' => $newer, 'status' => Order::STATUS_PENDING]).'" data-order-navigation="previous"', false)
            ->assertSee('href="'.route('orders.show', ['order' => $older, 'status' => Order::STATUS_PENDING]).'" data-order-navigation="next"', false)
            ->assertSee('href="'.route('orders.index', ['status' => Order::STATUS_PENDING]).'" data-page-navigation-loading', false)
            ->assertDontSee('href="'.route('orders.show', ['order' => $differentStatus, 'status' => Order::STATUS_PENDING]).'" data-order-navigation', false);

        $this->get(route('orders.show', ['order' => $current, 'status' => 'all']))
            ->assertOk()
            ->assertSee('href="'.route('orders.show', ['order' => $differentStatus, 'status' => 'all']).'" data-order-navigation="previous"', false)
            ->assertSee('href="'.route('orders.show', ['order' => $older, 'status' => 'all']).'" data-order-navigation="next"', false)
            ->assertSee('href="'.route('orders.index').'" data-page-navigation-loading', false);
    }

    public function test_order_navigation_disables_arrows_at_status_boundaries(): void
    {
        $order = $this->order(Order::STATUS_CANCELLED, '2026-10-15 11:00:00');
        $this->order(Order::STATUS_NEW, '2026-10-15 12:00:00');

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-order-navigation-disabled="previous"', false)
            ->assertSee('data-order-navigation-disabled="next"', false)
            ->assertSee('href="'.route('orders.index', ['status' => Order::STATUS_CANCELLED]).'" data-page-navigation-loading', false);
    }

    private function order(string $status, string $createdAt): Order
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => $status,
            'status_changed_at' => $createdAt,
            'currency' => 'PLN',
        ]);

        $order->timestamps = false;
        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $order->refresh();
    }
}
