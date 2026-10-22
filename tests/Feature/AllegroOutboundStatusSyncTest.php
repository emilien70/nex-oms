<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Enums\AllegroFulfillmentStatus;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\SyncAllegroOrderStatusJob;
use Modules\Integrations\Allegro\Listeners\DispatchAllegroStatusSync;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroClient;
use Modules\Integrations\Allegro\Services\AllegroOutboundStatusSyncService;
use Modules\Integrations\Allegro\Services\AllegroStatusMappingService;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroOutboundStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->assertUserAgent();
        parent::tearDown();
    }

    public function test_central_status_changes_enqueue_only_after_commit_and_rollback_sends_nothing(): void
    {
        [$order] = $this->linkedOrder();
        $order->update(['status' => 'new']);
        DB::beginTransaction();
        app(OrderStatusService::class)->change($order, 'pending', 'automation');
        Queue::assertNotPushed(SyncAllegroOrderStatusJob::class);
        DB::commit();
        Queue::assertPushed(SyncAllegroOrderStatusJob::class, fn ($job) => $job->orderId === $order->id && $job->queue === 'integrations');
        Queue::fake();
        DB::beginTransaction();
        app(OrderStatusService::class)->change($order, 'new', 'manual');
        DB::rollBack();
        Queue::assertNotPushed(SyncAllegroOrderStatusJob::class);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_listener_does_not_echo_allegro_source_and_duplicate_event_does_not_enqueue_twice(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $listener = app(DispatchAllegroStatusSync::class);
        $listener->handle(new OrderStatusChanged($order, 'new', 'pending', 'allegro'));
        Queue::assertNothingPushed();
        $event = new OrderStatusChanged($order, 'new', 'pending', 'manual');
        $listener->handle($event);
        $listener->handle($event);
        Queue::assertPushed(SyncAllegroOrderStatusJob::class, 1);
        $this->assertSame($event->eventId, $link->fresh()->status_sync_event_id);
    }

    public function test_job_reads_revision_then_puts_only_status_with_user_agent_and_records_success_once(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        $raw = Fixture::payload();
        $raw['revision'] = 'revision +/2';
        $this->fakeOrder($raw);
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        $job->handle(app(AllegroOutboundStatusSyncService::class));

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/fulfillment?checkoutForm.revision=revision%20%2B%2F2')
            && $request->data() === ['status' => 'PROCESSING']);
        Http::assertSentCount(2);
        $this->assertUserAgent();
        $this->assertSame('synced', $link->fresh()->status_sync_state);
        $this->assertSame('PROCESSING', $link->fresh()->fulfillment_status);
        $this->assertNotNull($link->fresh()->status_synced_at);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('100.03', $order->fresh()->paid_amount);
        $this->assertSame('Numery seryjne', $order->fresh()->notes);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_status_exported')->count());
        $log = DB::table('integration_api_logs')->where('operation', 'set_fulfillment')->first();
        $this->assertSame('PUT', $log->method);
        $this->assertSame('[]', $log->response_payload);
        $this->assertStringNotContainsString('Dostawcza', json_encode($log));
    }

    public function test_already_current_status_is_confirmed_without_put(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        $raw = Fixture::payload();
        $raw['fulfillment']['status'] = 'PROCESSING';
        $this->fakeOrder($raw);
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        Http::assertSentCount(1);
        $this->assertSame('synced', $link->fresh()->status_sync_state);
        $this->assertTrue($order->events()->where('event_type', 'allegro_status_exported')->sole()->payload['already_current']);
    }

    public function test_initial_and_custom_oms_statuses_can_both_be_exported_when_explicitly_mapped(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $custom = OrderStatusSetting::query()->create(['status' => 'my_service', 'short_name' => 'Moj serwis']);
        $initial = OrderStatusSetting::query()->where('status', 'new')->firstOrFail();
        $this->fakeOrder(Fixture::payload());
        foreach ([$initial, $custom] as $status) {
            $account->outboundStatusMappings()->create([
                'environment' => $account->environment, 'seller_user_id' => $account->allegro_user_id,
                'order_status_setting_id' => $status->id, 'allegro_status' => 'PROCESSING',
            ]);
            $order->update(['status' => $status->status]);
            $this->enqueue($order)->handle(app(AllegroOutboundStatusSyncService::class));
            $this->assertSame('synced', $link->fresh()->status_sync_state);
        }
        Http::assertSentCount(4);
        $this->assertSame('my_service', $order->fresh()->status);
    }

    public function test_disabled_unmapped_incomplete_cancelled_review_and_superseded_orders_never_enqueue(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $sync = app(AllegroOutboundStatusSyncService::class);
        foreach (['incomplete', 'review', 'cancelled', 'superseded'] as $state) {
            $link->update(['import_state' => $state]);
            $this->assertFalse($sync->enqueue($order->id, 'pending', (string) Str::uuid()));
        }
        $link->update(['import_state' => 'complete']);
        $account->update(['status_sync_enabled' => false]);
        $this->assertFalse($sync->enqueue($order->id, 'pending', (string) Str::uuid()));
        $account->update(['status_sync_enabled' => true]);
        $account->outboundStatusMappings()->delete();
        $this->assertFalse($sync->enqueue($order->id, 'pending', (string) Str::uuid()));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_mapping_change_invalidates_already_queued_job_without_backfill(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        Queue::fake();
        $id = OrderStatusSetting::query()->where('status', 'pending')->value('id');
        app(AllegroStatusMappingService::class)->update([$id => 'SENT'], true, $account->orderImportIdentity(), $account->status_sync_revision);
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        $this->assertSame('skipped', $link->fresh()->status_sync_state);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_newer_change_to_same_status_supersedes_old_job_and_its_failure(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $old = $this->enqueue($order);
        $order->update(['status' => 'new']);
        app(AllegroOutboundStatusSyncService::class)->enqueue($order->id, 'new', (string) Str::uuid());
        $order->update(['status' => 'pending']);
        $new = $this->enqueue($order);
        $old->handle(app(AllegroOutboundStatusSyncService::class));
        $old->failed(new \RuntimeException('old'));
        Http::assertNothingSent();
        $this->assertSame($new->eventId, $link->fresh()->status_sync_event_id);
        $this->assertSame('pending', $link->fresh()->status_sync_state);
    }

    public function test_account_environment_seller_and_deleted_orders_are_checked_again_before_http(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $original = $account->getAttributes();
        foreach ([['status_sync_enabled' => false], ['allegro_user_id' => 'other'], ['environment' => 'sandbox'], ['is_active' => false]] as $change) {
            $account->setRawAttributes($original)->save();
            $job = $this->enqueue($order);
            $account->update($change);
            $job->handle(app(AllegroOutboundStatusSyncService::class));
            $this->assertSame('skipped', $link->fresh()->status_sync_state);
        }
        $account->setRawAttributes($original)->save();
        $job = $this->enqueue($order);
        $order->delete();
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        Http::assertNothingSent();
    }

    public function test_status_changed_while_fetching_remote_order_prevents_put(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        Http::fake(function () use ($order) {
            $order->update(['status' => 'new']);

            return Http::response(Fixture::payload());
        });
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        Http::assertSentCount(1);
        $this->assertSame('skipped', $link->fresh()->status_sync_state);
    }

    public function test_actual_cancellation_is_imported_without_outbound_or_losing_local_notes_payment(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        $raw = Fixture::payload();
        $raw['status'] = 'CANCELLED';
        $this->fakeOrder($raw);
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        Http::assertSentCount(1);
        $this->assertSame('cancelled', $link->fresh()->import_state);
        $this->assertSame('CANCELLED', $link->fresh()->external_status);
        $this->assertSame('100.03', $order->fresh()->paid_amount);
        $this->assertSame('Numery seryjne', $order->fresh()->notes);
    }

    public function test_unsafe_remote_state_provider_missing_revision_and_wrong_id_are_rejected(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $raw = [];
        Http::fake(function () use (&$raw) {
            return Http::response($raw);
        });
        foreach ([['status' => 'FILLED_IN'], ['fulfillment' => ['status' => 'NEW', 'provider' => ['id' => 'ALLEGRO']]],
            ['revision' => ''], ['id' => (string) Str::uuid()]] as $change) {
            $job = $this->enqueue($order);
            $raw = array_replace(Fixture::payload(), $change);
            $job->handle(app(AllegroOutboundStatusSyncService::class));
            $this->assertSame('failed', $link->fresh()->status_sync_state);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_conflicting_revision_is_retried_with_a_fresh_get_and_revision(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        $reads = 0;
        Http::fake(function (Request $request) use (&$reads) {
            if ($request->method() === 'GET') {
                $raw = Fixture::payload();
                $raw['revision'] = 'revision-'.++$reads;

                return Http::response($raw);
            }

            return Http::response([], $reads === 1 ? 409 : 204);
        });
        try {
            $job->handle(app(AllegroOutboundStatusSyncService::class));
            $this->fail('A conflict must be retried by the queue.');
        } catch (AllegroApiException $exception) {
            $this->assertSame(409, $exception->statusCode);
        }
        $this->assertSame('pending', $link->fresh()->status_sync_state);
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), 'checkoutForm.revision=revision-2'));
        $this->assertSame('synced', $link->fresh()->status_sync_state);
        $this->assertUserAgent();
    }

    public function test_permanent_failure_is_safe_visible_and_can_be_retried_only_once(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response(Fixture::payload())
            : Http::response(['errors' => [['message' => '<script>secret</script>']]], 422));
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        $this->assertSame('failed', $link->fresh()->status_sync_state);
        $this->assertStringNotContainsString('secret', $link->fresh()->status_sync_error);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Ponów przekazanie statusu')->assertDontSee('<script>secret</script>', false);
        Queue::fake();
        $this->post(route('orders.allegro.status.retry', $order))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('orders.allegro.status.retry', $order))->assertSessionHasErrors('allegro_status_sync');
        Queue::assertPushed(SyncAllegroOrderStatusJob::class, 1);
        $this->assertSame('pending', $link->fresh()->status_sync_state);
        $this->assertNotSame($job->eventId, $link->fresh()->status_sync_event_id);
    }

    public function test_disabled_retry_keeps_previous_failure_and_foreign_seller_is_not_accessible(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        $job->failed(new \RuntimeException('internal details'));
        $error = $link->fresh()->status_sync_error;
        $account->update(['status_sync_enabled' => false]);
        Queue::fake();
        $this->post(route('orders.allegro.status.retry', $order))->assertSessionHasErrors('allegro_status_sync');
        $this->assertSame('failed', $link->fresh()->status_sync_state);
        $this->assertSame($error, $link->fresh()->status_sync_error);
        $account->update(['allegro_user_id' => 'other']);
        $this->post(route('orders.allegro.status.retry', $order))->assertNotFound();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_transient_errors_are_rethrown_and_exhaustion_is_recorded_once(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $status = 429;
        Http::fake(function () use (&$status) {
            return Http::response([], $status);
        });
        foreach ([429, 503] as $status) {
            $job = $this->enqueue($order);
            try {
                $job->handle(app(AllegroOutboundStatusSyncService::class));
                $this->fail('Transient errors must reach the queue.');
            } catch (AllegroApiException $exception) {
                $this->assertSame($status, $exception->statusCode);
            }
            $this->assertSame('pending', $link->fresh()->status_sync_state);
        }
        $job->failed(new \RuntimeException('private details'));
        $job->failed(new \RuntimeException('private details'));
        $this->assertSame('failed', $link->fresh()->status_sync_state);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_status_export_failed')->count());
        $this->assertStringNotContainsString('private', $link->fresh()->status_sync_error);
    }

    public function test_put_retry_after_unauthorized_preserves_body_method_and_exact_user_agent(): void
    {
        [, $account] = $this->linkedOrder();
        Http::fakeSequence('https://api.allegro.pl/order/checkout-forms/'.Fixture::ORDER_ID.'/fulfillment*')->push([], 401)->push([], 204);
        Http::fake(['https://allegro.pl/auth/oauth/token' => Http::response([
            'access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 43200,
        ])]);
        app(AllegroClient::class)->updateFulfillment($account, Fixture::ORDER_ID, AllegroFulfillmentStatus::Sent, 'revision-1');
        Http::assertSentCount(3);
        $puts = Http::recorded(fn (Request $request) => $request->method() === 'PUT');
        $this->assertCount(2, $puts);
        foreach ($puts as [$request]) {
            $this->assertSame(['status' => 'SENT'], $request->data());
        }
        $this->assertUserAgent();
        $this->assertSame('rotated-access', $account->fresh()->access_token);
    }

    public function test_deleting_configuration_does_not_delete_order_or_export_history(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $job = $this->enqueue($order);
        $this->fakeOrder(Fixture::payload());
        $job->handle(app(AllegroOutboundStatusSyncService::class));
        OrderStatusSetting::query()->where('status', 'pending')->firstOrFail()->forceDelete();
        $this->assertDatabaseCount('allegro_outbound_status_mappings', 0);
        $account->delete();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertNull($link->fresh()->allegro_account_id);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_status_exported')->count());
    }

    private function linkedOrder(): array
    {
        OrderStatusSetting::syncDefaults();
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill([
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1',
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'is_active' => true,
            'last_test_status' => AllegroAccount::STATUS_SUCCESS, 'status_sync_enabled' => true,
            'status_sync_identity' => 'production:seller-1', 'status_sync_revision' => 1,
        ])->save();
        $account->outboundStatusMappings()->create([
            'environment' => 'production', 'seller_user_id' => 'seller-1',
            'order_status_setting_id' => OrderStatusSetting::query()->where('status', 'pending')->value('id'), 'allegro_status' => 'PROCESSING',
        ]);
        $order = Order::query()->create([
            'source' => 'allegro', 'status' => 'pending', 'currency' => 'PLN', 'total_gross' => '100.03',
            'payment_status' => 'paid', 'paid_amount' => '100.03', 'notes' => 'Numery seryjne',
        ]);
        $link = AllegroOrderLink::query()->create([
            'allegro_account_id' => $account->id, 'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1', 'account_name' => 'multi-click',
            'external_order_id' => Fixture::ORDER_ID, 'external_status' => 'READY_FOR_PROCESSING', 'fulfillment_status' => 'NEW',
            'source_hash' => hash('sha256', 'order'), 'imported_at' => now(), 'import_state' => 'complete', 'completed_at' => now(),
        ]);

        return [$order, $account, $link];
    }

    private function enqueue(Order $order): SyncAllegroOrderStatusJob
    {
        $eventId = (string) Str::uuid();
        $this->assertTrue(app(AllegroOutboundStatusSyncService::class)->enqueue($order->id, $order->status, $eventId));
        $link = $order->allegroOrderLink()->firstOrFail();

        return new SyncAllegroOrderStatusJob($order->id, $order->status, $eventId, $link->allegro_account_id, $link->account->status_sync_revision);
    }

    private function fakeOrder(array $raw): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response($raw) : Http::response([], 204));
    }

    private function assertUserAgent(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
    }
}
