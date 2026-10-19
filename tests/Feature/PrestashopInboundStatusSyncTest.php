<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopInboundStatusesJob;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopOrderStatusJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Services\PrestashopInboundStatusSyncService;
use Modules\Integrations\Prestashop\Services\PrestashopStatusSyncDispatcher;
use Tests\TestCase;

class PrestashopInboundStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_sync_applies_inbound_mapping_and_records_history(): void
    {
        Queue::fake();
        [$order, $account, $link] = $this->linkedOrder();
        $this->mapInbound($account, 4, Order::STATUS_SHIPPED);
        $this->fakeOrders([
            ['id' => 13581, 'current_state' => 4, 'date_upd' => '2026-10-14 10:15:00'],
        ]);

        $result = app(PrestashopInboundStatusSyncService::class)
            ->sync($account->id, true);

        $this->assertSame([
            'checked' => 1,
            'changed' => 1,
            'observed' => 0,
            'pending' => 0,
        ], $result);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame(4, $link->fresh()->external_order_state_id);
        $this->assertSame('Wysłane', $link->fresh()->external_order_state_name);
        $this->assertNotNull($account->fresh()->last_status_sync_at);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_status_imported',
            'title' => 'Zmieniono status na podstawie PrestaShop',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'order_status_changed',
        ]);
        Queue::assertPushed(
            EvaluateAutomationEventJob::class,
            fn (EvaluateAutomationEventJob $job): bool => $job->eventName === 'order.status_changed'
                && ($job->eventPayload['source'] ?? null) === 'prestashop_status_sync',
        );
        Queue::assertNotPushed(SyncPrestashopOrderStatusJob::class);
    }

    public function test_missing_mapping_updates_remote_cache_without_changing_oms_status(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $this->fakeOrders([
            ['id' => 13581, 'current_state' => 4, 'date_upd' => '2026-10-14 10:15:00'],
        ]);

        $result = app(PrestashopInboundStatusSyncService::class)
            ->sync($account->id, true);

        $this->assertSame(1, $result['observed']);
        $this->assertSame(Order::STATUS_NEW, $order->fresh()->status);
        $this->assertSame(4, $link->fresh()->external_order_state_id);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_status_imported',
        ]);
    }

    public function test_unassigned_status_does_not_block_other_orders_and_later_valid_status_is_applied(): void
    {
        Queue::fake();
        [$firstOrder, $account, $firstLink] = $this->linkedOrder();
        $this->mapInbound($account, 4, Order::STATUS_SHIPPED);
        $secondOrder = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => 'PS-13582',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'payment_status' => 'unpaid',
        ]);
        $secondLink = $account->orderLinks()->create([
            'order_id' => $secondOrder->id,
            'external_order_id' => 13582,
            'external_reference' => 'PS-13582',
            'external_order_state_id' => 1,
            'external_order_state_name' => 'Oczekiwanie na płatność',
            'source_hash' => hash('sha256', 'prestashop-order-13582'),
            'imported_at' => now(),
        ]);
        Http::fake([
            'https://multi-click.pl/api/orders*' => Http::sequence()
                ->push(['orders' => [
                    ['id' => 13581, 'current_state' => 0, 'date_upd' => '2026-10-14 10:15:00'],
                    ['id' => 13582, 'current_state' => 4, 'date_upd' => '2026-10-14 10:15:00'],
                ]])
                ->push(['orders' => [
                    ['id' => 13581, 'current_state' => 4, 'date_upd' => '2026-10-14 10:16:00'],
                    ['id' => 13582, 'current_state' => 4, 'date_upd' => '2026-10-14 10:16:00'],
                ]]),
        ]);

        $result = app(PrestashopInboundStatusSyncService::class)->sync($account->id, true);

        $this->assertSame([
            'checked' => 2,
            'changed' => 1,
            'observed' => 1,
            'pending' => 0,
        ], $result);
        $this->assertSame(Order::STATUS_NEW, $firstOrder->fresh()->status);
        $this->assertNull($firstLink->fresh()->external_order_state_id);
        $this->assertNull($firstLink->fresh()->external_order_state_name);
        $this->assertSame(Order::STATUS_SHIPPED, $secondOrder->fresh()->status);
        $this->assertSame(4, $secondLink->fresh()->external_order_state_id);

        $laterResult = app(PrestashopInboundStatusSyncService::class)->sync($account->id, true);

        $this->assertSame(4, $firstLink->fresh()->external_order_state_id);
        $this->assertSame('Wysłane', $firstLink->fresh()->external_order_state_name);
        $this->assertSame(Order::STATUS_SHIPPED, $firstOrder->fresh()->status);
        $this->assertSame([
            'checked' => 2,
            'changed' => 1,
            'observed' => 1,
            'pending' => 0,
        ], $laterResult);
    }

    public function test_pending_outbound_change_prevents_inbound_status_from_reverting_oms(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->save();
        $link->forceFill([
            'external_order_state_id' => 4,
            'external_order_state_name' => 'Wysłane',
            'pending_outbound_state_id' => 4,
            'pending_outbound_event_id' => '7a640cd4-6457-45b8-a8fd-2670ccb39339',
            'pending_outbound_since' => now(),
        ])->save();
        $this->mapInbound($account, 1, Order::STATUS_PENDING);
        $this->fakeOrders([
            ['id' => 13581, 'current_state' => 1, 'date_upd' => '2026-10-14 10:15:00'],
        ]);

        $result = app(PrestashopInboundStatusSyncService::class)
            ->sync($account->id, true);

        $this->assertSame(1, $result['pending']);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame(4, $link->fresh()->external_order_state_id);
        $this->assertNotNull($link->fresh()->pending_outbound_event_id);
    }

    public function test_incremental_sync_uses_date_cursor_and_ignores_unlinked_orders(): void
    {
        [$order, $account] = $this->linkedOrder([
            'last_status_sync_at' => now()->subMinutes(5),
        ]);
        $this->mapInbound($account, 4, Order::STATUS_SHIPPED);
        $this->fakeOrders([
            ['id' => 13581, 'current_state' => 4, 'date_upd' => now()->subMinute()->format('Y-m-d H:i:s')],
            ['id' => 99999, 'current_state' => 4, 'date_upd' => now()->subMinute()->format('Y-m-d H:i:s')],
        ]);

        $result = app(PrestashopInboundStatusSyncService::class)
            ->sync($account->id);

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['changed']);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['date'] ?? null) === '1'
                && isset($query['filter']['date_upd'])
                && ($query['display'] ?? null) === '[id,current_state,date_upd]';
        });
    }

    public function test_manual_sync_endpoint_dispatches_full_scan_job(): void
    {
        Queue::fake();
        [, $account] = $this->linkedOrder();

        $this->post(route('integrations.prestashop.status-sync'))
            ->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'statuses']))
            ->assertSessionHas('success', 'Synchronizacja statusów PrestaShop została zlecona.');

        Queue::assertPushed(
            SyncPrestashopInboundStatusesJob::class,
            fn (SyncPrestashopInboundStatusesJob $job): bool => $job->accountId === $account->id
                && $job->fullScan
                && $job->queue === 'integrations',
        );
    }

    public function test_scheduler_dispatches_only_due_enabled_account(): void
    {
        Queue::fake();
        [, $account] = $this->linkedOrder([
            'status_sync_enabled' => true,
            'status_sync_interval_minutes' => 5,
            'last_status_sync_at' => now()->subMinutes(6),
        ]);

        $this->assertTrue(app(PrestashopStatusSyncDispatcher::class)->dispatchDue());
        Queue::assertPushed(SyncPrestashopInboundStatusesJob::class, 1);

        $account->forceFill(['last_status_sync_at' => now()])->save();
        $this->assertFalse(app(PrestashopStatusSyncDispatcher::class)->dispatchDue());
        Queue::assertPushed(SyncPrestashopInboundStatusesJob::class, 1);
    }

    public function test_permanent_api_failure_is_saved_without_retrying_job(): void
    {
        [, $account] = $this->linkedOrder();
        Http::fake([
            'https://multi-click.pl/api/orders*' => Http::response([], 403),
        ]);
        $job = new SyncPrestashopInboundStatusesJob($account->id, true);

        $job->handle(app(PrestashopInboundStatusSyncService::class));

        $account->refresh();
        $this->assertNotNull($account->last_status_sync_error_at);
        $this->assertSame(
            'Brak uprawnienia odczytu zasobu PrestaShop: orders.',
            $account->last_status_sync_error,
        );
        $this->assertNull($account->last_status_sync_at);
    }

    /**
     * @return array{Order, PrestashopAccount, PrestashopOrderLink}
     */
    private function linkedOrder(array $accountOverrides = []): array
    {
        OrderStatusSetting::syncDefaults();
        $account = PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
            'status_sync_enabled' => false,
            'status_sync_interval_minutes' => 1,
            'last_tested_at' => now(),
            'last_test_status' => PrestashopConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ], $accountOverrides));
        $account->orderStates()->createMany([
            [
                'external_id' => 1,
                'name' => 'Oczekiwanie na płatność',
                'is_available' => true,
                'refreshed_at' => now(),
            ],
            [
                'external_id' => 4,
                'name' => 'Wysłane',
                'shipped' => true,
                'is_available' => true,
                'refreshed_at' => now(),
            ],
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

    private function mapInbound(
        PrestashopAccount $account,
        int $externalStateId,
        string $omsStatus,
    ): void {
        $status = OrderStatusSetting::query()
            ->where('status', $omsStatus)
            ->firstOrFail();
        $account->inboundStatusMappings()->create([
            'external_order_state_id' => $externalStateId,
            'order_status_setting_id' => $status->id,
        ]);
    }

    /** @param array<int, array<string, int|string>> $orders */
    private function fakeOrders(array $orders): void
    {
        Http::fake([
            'https://multi-click.pl/api/orders*' => Http::response([
                'orders' => $orders,
            ]),
        ]);
    }
}
