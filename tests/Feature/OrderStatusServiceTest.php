<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_service_changes_order_and_dispatches_domain_event(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 0,
            'payment_status' => 'unpaid',
        ]);

        $changed = app(OrderStatusService::class)->change($order, Order::STATUS_PENDING, 'automation');

        $this->assertTrue($changed);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->status_changed_at);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'order_status_changed',
        ]);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $event): bool => $event->order->is($order)
            && $event->oldStatus === Order::STATUS_NEW
            && $event->newStatus === Order::STATUS_PENDING
            && $event->source === 'automation'
        );
    }
}
