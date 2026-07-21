<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderStatusSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_order_status_can_be_created_from_settings(): void
    {
        $this->post(route('settings.order-statuses.store'), [
            'name' => 'Serwis',
            'description' => 'Zamowienie wymaga obslugi serwisowej',
            'color' => '#22c55e',
        ])->assertRedirect();

        $this->assertDatabaseHas('order_status_settings', [
            'status' => 'serwis',
            'short_name' => 'Serwis',
            'full_name' => 'Zamowienie wymaga obslugi serwisowej',
            'color' => '#22c55e',
        ]);

        $this->get(route('settings.order-statuses.index'))
            ->assertOk()
            ->assertSee('Serwis')
            ->assertSee('Zamowienie wymaga obslugi serwisowej');
    }

    public function test_new_order_status_requires_name_description_and_color(): void
    {
        $this->post(route('settings.order-statuses.store'), [])
            ->assertSessionHasErrors(['name', 'description', 'color']);
    }

    public function test_order_status_definitions_are_loaded_once_while_rendering_the_list(): void
    {
        Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
        ]);
        Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_PENDING,
            'currency' => 'PLN',
        ]);

        $statusQueries = 0;

        DB::listen(function (QueryExecuted $query) use (&$statusQueries): void {
            if (str_contains($query->sql, 'from "order_status_settings"')) {
                $statusQueries++;
            }
        });

        $this->get(route('orders.index'))->assertOk();

        $this->assertLessThanOrEqual(3, $statusQueries);
    }
}
