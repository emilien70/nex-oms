<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersTrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_trash_list_exposes_only_bulk_actions_for_selected_orders(): void
    {
        $order = $this->createOrder(Order::STATUS_PENDING);
        $order->delete();

        $this->get(route('orders.index', ['trash' => 1]))
            ->assertOk()
            ->assertSee('data-order-select-all', false)
            ->assertSee('data-order-checkbox', false)
            ->assertSee(route('orders.bulk-force-delete'), false)
            ->assertSee(route('orders.bulk-restore'), false)
            ->assertSee('Nie zaznaczono &#380;adnego zam&oacute;wienia.', false)
            ->assertSee('confirmTrashDeleteModal', false)
            ->assertSee('Usu&#324; trwale', false)
            ->assertDontSee('trash-restore-popover', false)
            ->assertDontSee('href="'.route('orders.show', $order).'"', false)
            ->assertDontSee('formaction="'.route('orders.bulk-status').'"', false);

        $this->get(route('orders.show', $order))->assertNotFound();
    }

    public function test_selected_orders_are_restored_to_their_previous_statuses(): void
    {
        $pendingOrder = $this->createOrder(Order::STATUS_PENDING);
        $shippedOrder = $this->createOrder(Order::STATUS_SHIPPED);

        $pendingOrder->delete();
        $shippedOrder->delete();

        $this->post(route('orders.bulk-restore'), [
            'order_ids' => [$pendingOrder->id, $shippedOrder->id],
        ])->assertRedirect();

        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($pendingOrder->id)->status);
        $this->assertSame(Order::STATUS_SHIPPED, Order::findOrFail($shippedOrder->id)->status);
        $this->assertDatabaseCount('order_events', 2);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $pendingOrder->id,
            'event_type' => 'order_restored',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $shippedOrder->id,
            'event_type' => 'order_restored',
        ]);
    }

    public function test_selected_orders_are_permanently_deleted_with_related_data(): void
    {
        $order = $this->createOrder(Order::STATUS_NEW);
        $item = $order->items()->create([
            'product_name' => 'Produkt testowy',
            'quantity' => 1,
            'unit_price_gross' => 10,
            'total_price_gross' => 10,
        ]);
        $event = $order->events()->create([
            'event_type' => 'test_event',
            'title' => 'Test',
        ]);

        $order->delete();

        $this->post(route('orders.bulk-force-delete'), [
            'order_ids' => [$order->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('order_events', ['id' => $event->id]);
    }

    private function createOrder(string $status): Order
    {
        return Order::create([
            'source' => 'manual',
            'status' => $status,
            'status_changed_at' => now(),
            'currency' => 'PLN',
        ]);
    }
}
