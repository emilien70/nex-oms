<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_list_uses_supported_page_sizes(): void
    {
        foreach (range(1, 25) as $number) {
            Order::create([
                'source' => 'manual',
                'status' => Order::STATUS_NEW,
                'status_changed_at' => now(),
                'currency' => 'PLN',
            ]);
        }

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('class="nex-pagination-toolbar"', false)
            ->assertSee('class="nex-page-range dropdown-toggle"', false)
            ->assertSee('class="nex-pagination-total"', false)
            ->assertSee('class="btn-group btn-group-sm nex-page-navigation"', false)
            ->assertViewHas('orders', function ($orders): bool {
                return $orders->perPage() === 20
                    && $orders->count() === 20
                    && $orders->total() === 25;
            });

        $this->get(route('orders.index', ['per_page' => 30]))
            ->assertOk()
            ->assertViewHas('orders', function ($orders): bool {
                return $orders->perPage() === 30
                    && $orders->count() === 25
                    && $orders->total() === 25;
            });

        $this->get(route('orders.index', ['per_page' => 999]))
            ->assertOk()
            ->assertViewHas('perPage', 20);
    }
}
