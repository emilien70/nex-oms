<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
