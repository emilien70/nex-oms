<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Shipments\Models\Shipment;
use Tests\TestCase;

class OrdersAjaxListTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_list_exposes_ajax_state_metadata(): void
    {
        $order = $this->order();

        $response = $this->get(route('orders.index'));

        $response
            ->assertOk()
            ->assertSee('data-orders-page', false)
            ->assertSee('data-list-signature=', false)
            ->assertSee('data-all-matching-order-ids=', false);

        $state = $this->getJson(route('orders.list-state'));

        $state
            ->assertOk()
            ->assertJsonStructure([
                'signature',
                'status_counts',
                'trash_count',
                'checked_at',
            ]);
        $this->assertSame(1, $state->json('status_counts.'.Order::STATUS_NEW));

        $oldSignature = $state->json('signature');
        $order->update(['status' => Order::STATUS_PENDING]);

        $newState = $this->getJson(route('orders.list-state'));

        $this->assertNotSame($oldSignature, $newState->json('signature'));
        $this->assertSame(0, $newState->json('status_counts.'.Order::STATUS_NEW));
        $this->assertSame(1, $newState->json('status_counts.'.Order::STATUS_PENDING));
    }

    public function test_bulk_list_mutations_return_ajax_refresh_instructions(): void
    {
        $order = $this->order();

        $this->postJson(route('orders.bulk-status'), [
            'order_ids' => [$order->id],
            'status' => Order::STATUS_PENDING,
        ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('refresh.0', 'list')
            ->assertJsonPath('refresh.1', 'counts');

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);

        $this->postJson(route('orders.bulk-trash'), [
            'order_ids' => [$order->id],
        ])
            ->assertOk()
            ->assertJsonPath('saved', true);

        $this->assertSoftDeleted('orders', ['id' => $order->id]);

        $this->postJson(route('orders.bulk-restore'), [
            'order_ids' => [$order->id],
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'list');

        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_delivery_icon_is_highlighted_when_order_has_a_shipment(): void
    {
        $order = $this->order();
        $oldSignature = $this->getJson(route('orders.list-state'))->json('signature');

        $order->shipments()->create([
            'provider' => 'inpost_lockers',
            'service' => 'inpost_locker_standard',
            'status' => Shipment::STATUS_CONFIRMED,
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('orders-icon orders-icon-shipping-active', false);

        $this->assertNotSame(
            $oldSignature,
            $this->getJson(route('orders.list-state'))->json('signature')
        );
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
            'total_gross' => 0,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
        ]);
    }
}
