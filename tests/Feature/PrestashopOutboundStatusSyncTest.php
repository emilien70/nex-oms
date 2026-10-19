<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopOrderStatusJob;
use Modules\Integrations\Prestashop\Listeners\DispatchPrestashopStatusSync;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Services\PrestashopOutboundStatusSyncService;
use Tests\TestCase;

class PrestashopOutboundStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapped_status_change_dispatches_sync_job(): void
    {
        Queue::fake();
        [$order, , $link] = $this->linkedOrder();
        $event = new OrderStatusChanged($order, Order::STATUS_NEW, Order::STATUS_SHIPPED, 'manual');

        app(DispatchPrestashopStatusSync::class)->handle($event);

        Queue::assertPushed(
            SyncPrestashopOrderStatusJob::class,
            fn (SyncPrestashopOrderStatusJob $job): bool => $job->orderId === $order->id
                && $job->expectedOmsStatus === Order::STATUS_SHIPPED
                && $job->eventId === $event->eventId
                && $job->queue === 'integrations',
        );
        $this->assertSame(4, $link->fresh()->pending_outbound_state_id);
        $this->assertSame($event->eventId, $link->fresh()->pending_outbound_event_id);
    }

    public function test_prestashop_source_and_unmapped_orders_do_not_dispatch_sync_job(): void
    {
        Queue::fake();
        [$order, $account] = $this->linkedOrder();

        app(DispatchPrestashopStatusSync::class)->handle(
            new OrderStatusChanged($order, Order::STATUS_NEW, Order::STATUS_SHIPPED, 'prestashop'),
        );

        $account->outboundStatusMappings()->delete();

        app(DispatchPrestashopStatusSync::class)->handle(
            new OrderStatusChanged($order, Order::STATUS_NEW, Order::STATUS_SHIPPED, 'manual'),
        );

        Queue::assertNotPushed(SyncPrestashopOrderStatusJob::class);
    }

    public function test_job_posts_xml_history_updates_link_and_records_success(): void
    {
        [$order, $account, $link] = $this->linkedOrder([
            'notify_customer_on_status_change' => true,
        ]);
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->save();

        Http::fake([
            'https://multi-click.pl/api/orders/13581*' => Http::response([
                'order' => ['id' => 13581, 'current_state' => 1],
            ]),
            'https://multi-click.pl/api/order_histories*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><prestashop><order_history><id>901</id></order_history></prestashop>',
                201,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        $job = new SyncPrestashopOrderStatusJob(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-success',
        );
        $job->handle(app(PrestashopOutboundStatusSyncService::class));

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/api/order_histories?')) {
                return false;
            }

            return str_contains($request->url(), 'sendemail=1')
                && str_contains($request->body(), '<id_order>13581</id_order>')
                && str_contains($request->body(), '<id_order_state>4</id_order_state>');
        });
        $this->assertDatabaseHas('prestashop_order_links', [
            'id' => $link->id,
            'external_order_state_id' => 4,
            'external_order_state_name' => 'Wysłane',
            'pending_outbound_event_id' => null,
        ]);
        $event = $order->events()
            ->where('event_type', 'prestashop_status_exported')
            ->firstOrFail();
        $this->assertSame(901, $event->payload['order_history_id']);
        $this->assertTrue($event->payload['customer_notified']);
        $this->assertSame($account->id, $link->prestashop_account_id);
    }

    public function test_job_can_assign_first_valid_status_when_remote_order_has_no_status(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->save();
        $link->forceFill([
            'external_order_state_id' => null,
            'external_order_state_name' => null,
        ])->save();

        Http::fake([
            'https://multi-click.pl/api/orders/13581*' => Http::response([
                'order' => ['id' => 13581, 'current_state' => 0],
            ]),
            'https://multi-click.pl/api/order_histories*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><prestashop><order_history><id>902</id></order_history></prestashop>',
                201,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        app(PrestashopOutboundStatusSyncService::class)->sync(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-first-status',
        );

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->body(), '<id_order_state>4</id_order_state>'));
        $this->assertSame(4, $link->fresh()->external_order_state_id);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_status_exported',
        ]);
    }

    public function test_already_current_remote_status_does_not_create_duplicate_history(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->save();

        Http::fake([
            'https://multi-click.pl/api/orders/13581*' => Http::response([
                'order' => ['id' => 13581, 'current_state' => 4],
            ]),
        ]);

        app(PrestashopOutboundStatusSyncService::class)->sync(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-current',
        );

        Http::assertSentCount(1);
        Http::assertNotSent(
            fn (Request $request): bool => $request->method() === 'POST',
        );
        $this->assertSame(4, $link->fresh()->external_order_state_id);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_status_exported',
        ]);
    }

    public function test_stale_job_does_not_contact_prestashop(): void
    {
        [$order] = $this->linkedOrder();
        $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();
        Http::fake();

        app(PrestashopOutboundStatusSyncService::class)->sync(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-stale',
        );

        Http::assertNothingSent();
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_status_exported',
        ]);
    }

    public function test_permanent_write_failure_is_recorded_without_retry_exception(): void
    {
        [$order] = $this->linkedOrder();
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->save();

        Http::fake([
            'https://multi-click.pl/api/orders/13581*' => Http::response([
                'order' => ['id' => 13581, 'current_state' => 1],
            ]),
            'https://multi-click.pl/api/order_histories*' => Http::response([], 403),
        ]);

        $job = new SyncPrestashopOrderStatusJob(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-permanent-failure',
        );
        $job->handle(app(PrestashopOutboundStatusSyncService::class));

        $event = $order->events()
            ->where('event_type', 'prestashop_status_export_failed')
            ->firstOrFail();
        $this->assertSame(403, $event->payload['status_code']);
        $this->assertSame(
            'Brak uprawnienia zapisu zasobu PrestaShop: order_histories.',
            $event->description,
        );
    }

    public function test_transient_failure_is_rethrown_for_queue_retry(): void
    {
        [$order] = $this->linkedOrder();
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->save();

        Http::fake([
            'https://multi-click.pl/api/orders/13581*' => Http::response([], 503),
        ]);

        $job = new SyncPrestashopOrderStatusJob(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-transient-failure',
        );

        $this->expectException(PrestashopApiException::class);

        $job->handle(app(PrestashopOutboundStatusSyncService::class));
    }

    public function test_exhausted_transient_failure_is_recorded_once(): void
    {
        [$order] = $this->linkedOrder();
        $job = new SyncPrestashopOrderStatusJob(
            $order->id,
            Order::STATUS_SHIPPED,
            'event-exhausted-failure',
        );

        $job->failed(new \RuntimeException('PrestaShop nadal jest niedostępny.'));
        $job->failed(new \RuntimeException('PrestaShop nadal jest niedostępny.'));

        $this->assertSame(
            1,
            $order->events()
                ->where('event_type', 'prestashop_status_export_failed')
                ->where('payload->event_id', 'event-exhausted-failure')
                ->count(),
        );
    }

    /** @return array{Order, PrestashopAccount, PrestashopOrderLink} */
    private function linkedOrder(array $accountOverrides = []): array
    {
        OrderStatusSetting::syncDefaults();
        $account = PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
            'notify_customer_on_status_change' => false,
            'last_tested_at' => now(),
            'last_test_status' => PrestashopConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ], $accountOverrides));
        $account->orderStates()->create([
            'external_id' => 4,
            'name' => 'Wysłane',
            'shipped' => true,
            'is_available' => true,
            'refreshed_at' => now(),
        ]);
        $shippedStatus = OrderStatusSetting::query()
            ->where('status', Order::STATUS_SHIPPED)
            ->firstOrFail();
        $account->outboundStatusMappings()->create([
            'order_status_setting_id' => $shippedStatus->id,
            'external_order_state_id' => 4,
        ]);
        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => 'PS-13581',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'payment_status' => 'unpaid',
        ]);
        $link = $account->orderLinks()->create([
            'order_id' => $order->id,
            'external_order_id' => 13581,
            'external_reference' => 'PS-13581',
            'external_order_state_id' => 1,
            'external_order_state_name' => 'Oczekiwanie na płatność',
            'source_hash' => hash('sha256', 'prestashop-order-13581'),
            'imported_at' => now(),
        ]);

        return [$order, $account, $link];
    }
}
