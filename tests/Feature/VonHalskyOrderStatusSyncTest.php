<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Events\OrderPaymentRegistered;
use App\Events\OrderStatusChanged;
use App\Models\OrderStatusSetting;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Integrations\VonHalsky\Jobs\DiscoverVonHalskyOrdersJob;
use Modules\Integrations\VonHalsky\Jobs\SyncVonHalskyOrderStatusJob;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportDispatcher;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderStatusSyncService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Shipments\Models\Shipment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\VonHalsky\CatalogFixtures;
use Tests\TestCase;

class VonHalskyOrderStatusSyncTest extends TestCase
{
    use CatalogFixtures, CreatesInvoiceStage2CDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', config('app.timezone')));
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->sourceOrder())]);
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        OrderStatusSetting::syncDefaults();
    }

    #[DataProvider('statuses')]
    public function test_statuses_are_informational_and_negative_states_have_distinct_warnings(string $status, string $label, bool $negative): void
    {
        [$account, $order, $link] = $this->importedOrder();
        $before = $order->getAttributes();
        $this->respond($this->response($status));
        $job = $this->queueStatus($account);
        $this->runStatus($job);
        $this->assertSame($status, $link->fresh()->external_status);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertNotNull($link->fresh()->last_status_checked_at);
        $this->assertSame('2026-10-30 10:10:00.123456', $link->fresh()->getRawOriginal('external_status_updated_at'));
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Status Von Halsky:')->assertSee($label);
        $list = $this->get(route('orders.index'))->assertOk();
        if ($negative) {
            $list->assertSee('order-indicator cancelled', false)->assertSee('!!');
            $this->assertStringContainsString($link->fresh()->externalOrderStatus()->warning(), $list->getContent());
        } else {
            $list->assertDontSee('order-indicator cancelled', false);
        }
        $this->assertSame($status === 'ACCEPTED' ? 0 : 1, $order->events()->where('event_type', 'von_halsky_status_changed')->count());
        $this->assertSame($status, VonHalskyOrderImportAttempt::query()->sole()->external_status);
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 1);
        $this->assertDatabaseCount('automation_runs', 0);
        Mail::assertNothingSent();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders/PG76P11'));
    }

    public static function statuses(): array
    {
        return [
            ['CREATED', 'Utworzone', false], ['ACCEPTED', 'Zaakceptowane', false],
            ['CANCELED', 'Anulowane przez kupującego', true], ['REFUSED', 'Odrzucone przez sprzedawcę', true],
            ['REJECTED', 'Odrzucone przez platformę', true], ['UNKNOWN', 'Status wymaga sprawdzenia', false],
            ['FUTURE_STATUS', 'Status wymaga sprawdzenia', false],
        ];
    }

    public function test_sent_order_invoice_items_payments_and_shipment_are_preserved(): void
    {
        [$account, $order, $link] = $this->importedOrder();
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext('2026-10-30 10:00:00'));
        $order->forceFill(['status' => 'shipped', 'notes' => 'Keep notes'])->save();
        Shipment::query()->create(['order_id' => $order->id, 'provider' => 'inpost',
            'service' => Shipment::SERVICE_INPOST_LOCKER_STANDARD, 'status' => 'confirmed',
            'tracking_number' => 'TEST_TRACK', 'request_uuid' => (string) Str::uuid()]);
        $tables = ['orders', 'order_items', 'invoices', 'invoice_items', 'invoice_series',
            'invoice_number_counters', 'invoice_number_counter_adjustments', 'order_document_slots', 'shipments', 'automation_runs'];
        $before = $this->snapshot($tables);
        $excluded = ['external_status', 'external_status_updated_at', 'last_status_checked_at',
            'last_status_sync_requested_at', 'last_status_sync_error', 'updated_at'];
        $linkBefore = Arr::except($link->getAttributes(), $excluded);
        Queue::fake();
        Event::fake([OrderCreated::class, OrderDataCompleted::class, OrderPaymentRegistered::class, OrderStatusChanged::class]);
        $this->respond($this->response('CANCELED'));
        $job = $this->queueStatus($account);
        $this->runStatus($job);
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame($linkBefore, Arr::except($link->fresh()->getAttributes(), $excluded));
        $this->assertSame($invoice->number, $invoice->fresh()->number);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->get(route('orders.index'))->assertOk()->assertSee('!!')->assertSee('Opłacone');
        $this->assertSame('integrations', $job->queue);
        $this->assertCount(1, Queue::pushedJobs());
        Event::assertNothingDispatched();
    }

    public function test_repeated_status_has_one_event_and_one_import_history_row(): void
    {
        [$account, $order] = $this->importedOrder();
        $this->respond($this->response('CANCELED'));
        $this->runStatus($this->queueStatus($account));
        $this->travel(2)->minutes();
        $this->runStatus($this->queueStatus($account));
        $this->assertSame(1, $order->events()->where('event_type', 'von_halsky_status_changed')->count());
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_known_order_is_checked_before_import_start_and_manual_check_works_without_a_start_date(): void
    {
        [$account, $order] = $this->importedOrder();
        $account->update(['order_import_enabled' => false, 'order_import_started_at' => null]);
        $this->respond($this->response('REFUSED'));
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertOk();
        $this->post(route('integrations.von-halsky.check-now'), ['expected_lock_version' => $account->lock_version])
            ->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertNotPushed(DiscoverVonHalskyOrdersJob::class);
        $job = Queue::pushed(SyncVonHalskyOrderStatusJob::class)->sole();
        $this->assertTrue($job->manual);
        Http::assertNothingSent();
        $this->runStatus($job);
        $this->assertSame('REFUSED', $order->vonHalskyOrderLink()->sole()->external_status);
        $this->assertFalse($account->fresh()->order_import_enabled);
    }

    #[DataProvider('intervals')]
    public function test_existing_dispatcher_honors_the_shared_interval(int $minutes): void
    {
        [$account] = $this->importedOrder(['order_import_interval_minutes' => $minutes]);
        $dispatcher = app(VonHalskyOrderImportDispatcher::class);
        $dispatcher->dispatchDue();
        Queue::assertPushed(DiscoverVonHalskyOrdersJob::class, 1);
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 1);
        $this->respond($this->response('ACCEPTED'));
        $this->runStatus(Queue::pushed(SyncVonHalskyOrderStatusJob::class)->sole());
        $dispatcher->dispatchDue();
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 1);
        $this->travel($minutes)->minutes();
        $dispatcher->dispatchDue();
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 2);
    }

    public static function intervals(): array
    {
        return [[1], [10]];
    }

    #[DataProvider('intervals')]
    public function test_status_interval_matches_the_scheduler_second_precision_despite_microsecond_jitter(int $minutes): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00.800000', config('app.timezone')));
        [$account] = $this->importedOrder(['order_import_interval_minutes' => $minutes]);
        $dispatcher = app(VonHalskyOrderImportDispatcher::class);
        $dispatcher->dispatchDue();
        $this->respond($this->response('ACCEPTED'));
        $this->runStatus(Queue::pushed(SyncVonHalskyOrderStatusJob::class)->sole());
        $this->travelTo(now()->startOfSecond()->addMinutes($minutes)->addMicroseconds(200000));
        $dispatcher->dispatchDue();
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 2);
    }

    public function test_manual_repeat_does_not_replace_an_inflight_reservation(): void
    {
        [$account, , $link] = $this->importedOrder();
        $first = $this->queueStatus($account, manual: true, force: true);
        $this->travel(31)->seconds();
        $this->assertSame(0, $this->service()->dispatchDue($account, manual: true, force: true));
        $this->assertSame($first->requestedAt, $link->fresh()->getRawOriginal('last_status_sync_requested_at'));
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 1);
    }

    public function test_old_source_response_does_not_revert_status_or_success_timestamp(): void
    {
        [$account, $order, $link] = $this->importedOrder();
        $this->respond($this->response('CANCELED'));
        $this->runStatus($this->queueStatus($account));
        $checked = $link->fresh()->getRawOriginal('last_status_checked_at');
        $this->travel(2)->minutes();
        $this->respond($this->response('ACCEPTED', ['updatedAt' => '2026-10-29T09:00:00Z']));
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('CANCELED', $link->fresh()->external_status);
        $this->assertSame($checked, $link->fresh()->getRawOriginal('last_status_checked_at'));
        $this->assertNotNull($link->fresh()->last_status_sync_error);
        $this->assertSame(1, $order->events()->where('event_type', 'von_halsky_status_changed')->count());
    }

    public function test_optional_updated_at_is_not_fabricated_from_creation_time(): void
    {
        [$account, , $link] = $this->importedOrder();
        $data = $this->response('CANCELED');
        unset($data['updatedAt']);
        $this->respond($data);
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('CANCELED', $link->fresh()->external_status);
        $this->assertNotNull($link->fresh()->last_status_checked_at);
        $this->assertNull($link->fresh()->external_status_updated_at);
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_response_preserves_the_saved_state(array $override): void
    {
        [$account, $order, $link] = $this->importedOrder();
        $this->respond(array_replace($this->response('CANCELED'), $override));
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_checked_at);
        $this->assertNotNull($link->fresh()->last_status_sync_error);
        $this->assertSame(0, $order->events()->where('event_type', 'von_halsky_status_changed')->count());
    }

    public static function invalidResponses(): array
    {
        return [
            [['id' => 'OTHER']], [['organizationId' => '497f6eca-6276-4993-bfeb-53cbbbba6f08']],
            [['status' => null]], [['status' => []]], [['status' => '<script>x</script>']],
            [['status' => str_repeat('A', 33)]], [['createdAt' => null]], [['updatedAt' => 'invalid']],
            [['updatedAt' => '2026-10-27T00:00:00Z']],
        ];
    }

    public function test_transient_error_retains_status_and_later_scan_recovers(): void
    {
        [$account, , $link] = $this->importedOrder();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*' => Http::response([], 503)]);
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_checked_at);
        $this->assertNotNull($link->fresh()->last_status_sync_error);
        $this->travel(2)->minutes();
        $this->respond($this->response('REJECTED'));
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('REJECTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_sync_error);
    }

    public function test_completed_job_and_late_failure_do_not_repeat_requests_or_replace_success(): void
    {
        [$account, $order, $link] = $this->importedOrder();
        $this->respond($this->response('CANCELED'));
        $job = $this->queueStatus($account);
        $this->runStatus($job);
        $before = $link->fresh()->getAttributes();
        $this->runStatus($job);
        $job->failed(new RuntimeException('Late private failure'));
        $this->assertSame($before, $link->fresh()->getAttributes());
        $this->assertSame(1, $order->events()->where('event_type', 'von_halsky_status_changed')->count());
        Http::assertSentCount(1);
    }

    public function test_rate_limit_is_shared_and_does_not_repeat_http_before_retry_after(): void
    {
        [$account, , $link] = $this->importedOrder();
        $account->update(['token_expires_at' => now()->addHour()]);
        Http::swap((new Factory)->preventStrayRequests());
        Http::fakeSequence()->push([], 429, ['Retry-After' => '900'])->push($this->response('CANCELED'));
        $this->runStatus($this->queueStatus($account));
        $this->assertNotNull($link->fresh()->last_status_sync_error);
        $this->travel(2)->minutes();
        $this->runStatus($this->queueStatus($account));
        Http::assertSentCount(1);
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->travel(14)->minutes();
        $this->runStatus($this->queueStatus($account));
        Http::assertSentCount(2);
        $this->assertSame('CANCELED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_sync_error);
    }

    public function test_connection_error_is_safe_and_preserves_the_last_status(): void
    {
        [$account, $order, $link] = $this->importedOrder();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(fn () => throw new ConnectionException('SECRET_TOKEN and private endpoint'));
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_checked_at);
        $this->assertNotNull($link->fresh()->last_status_sync_error);
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('SECRET_TOKEN');
        $this->assertStringNotContainsString('SECRET_TOKEN', $link->fresh()->last_status_sync_error);
    }

    public function test_abandoned_reservation_is_recovered_and_the_old_job_cannot_write(): void
    {
        [$account, , $link] = $this->importedOrder();
        $old = $this->queueStatus($account);
        $this->travel(181)->seconds();
        $next = $this->queueStatus($account);
        $this->assertNotSame($old->requestedAt, $next->requestedAt);
        $this->respond($this->response('CANCELED'));
        $old->handle($this->service());
        Http::assertNothingSent();
        $old->failed(new RuntimeException('Expired failure'));
        $this->assertNull($link->fresh()->last_status_sync_error);
        $this->runStatus($next);
        $this->assertSame('CANCELED', $link->fresh()->external_status);
    }

    public function test_dispatch_is_bounded_and_does_not_starve_an_unchecked_order(): void
    {
        [$account, $order, $link] = $this->importedOrder();
        for ($index = 1; $index <= 100; $index++) {
            $copy = $order->replicate()->forceFill(['external_id' => 'TEST_'.$index]);
            $copy->save();
            $link->replicate()->forceFill(['order_id' => $copy->id, 'external_order_id' => $copy->external_id])->save();
        }
        $this->respond($this->response('ACCEPTED'));
        $this->assertSame(100, $this->service()->dispatchDue($account));
        $this->assertSame(1, $this->service()->dispatchDue($account));
        $this->assertSame(0, $this->service()->dispatchDue($account));
        Queue::assertPushed(SyncVonHalskyOrderStatusJob::class, 101);
        Http::assertNothingSent();
    }

    public function test_configuration_change_during_http_rejects_the_old_result_and_error(): void
    {
        [$account, , $link] = $this->importedOrder();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function () use ($account) {
            $account->update(['order_import_revision' => 1]);

            return Http::response($this->response('CANCELED'));
        });
        $job = $this->queueStatus($account);
        $this->runStatus($job);
        $job->failed(new RuntimeException('Sensitive internal information'));
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_checked_at);
        $this->assertNull($link->fresh()->last_status_sync_error);
    }

    public function test_new_reservation_prevents_old_worker_and_failure_from_overwriting_it(): void
    {
        [$account, , $link] = $this->importedOrder();
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function () use ($link) {
            DB::table('von_halsky_order_links')->where('id', $link->id)->update([
                'last_status_sync_requested_at' => now()->addSecond()->format('Y-m-d H:i:s.u'),
            ]);

            return Http::response($this->response('CANCELED'));
        });
        $job = $this->queueStatus($account);
        $this->runStatus($job);
        $job->failed(new RuntimeException('Old failure'));
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_sync_error);
    }

    #[DataProvider('invalidLinks')]
    public function test_mismatched_or_deleted_links_are_not_checked(string $mode): void
    {
        [$account, $order, $link] = $this->importedOrder();
        $job = $this->queueStatus($account);
        match ($mode) {
            'environment' => $link->update(['environment' => 'stage']),
            'organization' => $link->update(['organization_id' => '497f6eca-6276-4993-bfeb-53cbbbba6f08']),
            'account' => $link->update(['von_halsky_account_id' => null]),
            'source' => $order->update(['source' => 'manual']),
            'external' => $order->update(['external_id' => 'OTHER']),
            'trash' => $order->delete(),
            'delete' => $order->forceDelete(),
            'disabled' => $account->update(['order_import_enabled' => false]),
            'disconnect' => $account->update(['is_active' => false]),
        };
        $this->respond($this->response('CANCELED'));
        $this->runStatus($job);
        Http::assertNothingSent();
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
    }

    public static function invalidLinks(): array
    {
        return array_map(fn ($mode) => [$mode], ['environment', 'organization', 'account', 'source', 'external', 'trash', 'delete', 'disabled', 'disconnect']);
    }

    public function test_history_failure_rolls_back_status_and_no_automation_event_is_emitted(): void
    {
        [$account, $order, $link] = $this->importedOrder();
        DB::statement("CREATE TRIGGER vh_status_fail BEFORE INSERT ON order_events WHEN NEW.event_type = 'von_halsky_status_changed' BEGIN SELECT RAISE(ABORT, 'FAIL'); END");
        $this->respond($this->response('CANCELED'));
        $this->runStatus($this->queueStatus($account));
        $this->assertSame('ACCEPTED', $link->fresh()->external_status);
        $this->assertNull($link->fresh()->last_status_checked_at);
        $this->assertNotNull($link->fresh()->last_status_sync_error);
        $this->assertSame(0, $order->events()->where('event_type', 'von_halsky_status_changed')->count());
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 0);
        $this->assertCount(1, Queue::pushedJobs());
    }

    public function test_state_poll_updates_status_warning_and_history_without_marketplace_requests(): void
    {
        [$account, $order] = $this->importedOrder();
        $first = $this->getJson(route('orders.state', $order))->assertOk();
        $this->respond($this->response('CANCELED'));
        $this->runStatus($this->queueStatus($account));
        $next = $this->getJson(route('orders.state', $order).'?'.http_build_query([
            'von_halsky_status_signature' => $first->json('von_halsky_status_signature'), 'latest_event_id' => $first->json('latest_event_id'),
        ]))->assertOk()->assertJsonPath('status', 'new');
        $this->assertStringContainsString('CANCELED', $next->json('fragments.von-halsky-status'));
        $this->assertStringContainsString('anulowane przez kupującego', $next->json('fragments.von-halsky-warning'));
        $this->assertArrayHasKey('history', $next->json('fragments'));
        $unchanged = $this->getJson(route('orders.state', $order).'?'.http_build_query([
            'von_halsky_status_signature' => $next->json('von_halsky_status_signature'),
        ]))->assertOk();
        $this->assertArrayNotHasKey('von-halsky-status', $unchanged->json('fragments'));
        Http::assertSentCount(1);
    }

    public function test_unrecorded_migration_does_not_dispatch_status_work(): void
    {
        [$account] = $this->importedOrder();
        DB::table('migrations')->where('migration', '2026_10_30_010000_add_von_halsky_order_status_sync')->delete();
        $this->assertFalse($this->service()->ready());
        $this->assertSame(0, $this->service()->dispatchDue($account));
        Queue::assertNothingPushed();
    }

    public function test_missing_migration_column_does_not_dispatch_status_work(): void
    {
        [$account] = $this->importedOrder();
        Schema::table('von_halsky_order_links', fn ($table) => $table->dropColumn('last_status_sync_error'));
        $this->assertFalse($this->service()->ready());
        $this->assertSame(0, $this->service()->dispatchDue($account));
        Queue::assertNothingPushed();
    }

    public function test_unused_migration_roundtrip_preserves_the_existing_link_and_relations(): void
    {
        [, , $link] = $this->importedOrder();
        $before = $link->getAttributes();
        $migration = require database_path('migrations/2026_10_30_010000_add_von_halsky_order_status_sync.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('von_halsky_order_links', 'last_status_sync_error'));
        $migration->up();
        $this->assertEqualsCanonicalizing($before, $link->fresh()->getAttributes());
        $this->assertNotNull($link->fresh()->order);
        $this->assertNotNull($link->fresh()->account);
    }

    public function test_used_status_migration_cannot_be_rolled_back(): void
    {
        [$account] = $this->importedOrder();
        $this->queueStatus($account);
        $migration = require database_path('migrations/2026_10_30_010000_add_von_halsky_order_status_sync.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function importedOrder(array $settings = []): array
    {
        $account = $this->account(array_replace(['order_import_enabled' => true, 'order_import_interval_minutes' => 1,
            'order_import_started_at' => now(), 'default_vat_rate' => '23.00'], $settings));
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11', [1])['import'];
        $order = app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', $preview['source_hash'], [1], true);

        return [$account, $order, $order->vonHalskyOrderLink()->sole()];
    }

    private function response(string $status, array $override = []): array
    {
        return array_replace(['id' => 'PG76P11', 'organizationId' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67',
            'createdAt' => '2026-10-28T09:17:51.841Z', 'updatedAt' => '2026-10-30T09:10:00.123456Z', 'status' => $status], $override);
    }

    private function respond(array $data): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*' => Http::response($data)]);
    }

    private function queueStatus($account, bool $manual = false, bool $force = false): SyncVonHalskyOrderStatusJob
    {
        $this->assertSame(1, $this->service()->dispatchDue($account, $manual, $force));

        return Queue::pushed(SyncVonHalskyOrderStatusJob::class)->last();
    }

    private function service(): VonHalskyOrderStatusSyncService
    {
        return app(VonHalskyOrderStatusSyncService::class);
    }

    private function runStatus(SyncVonHalskyOrderStatusJob $job): void
    {
        try {
            $job->handle($this->service());
        } finally {
            // QueueFake does not release the unique lock as the real worker does.
            app(UniqueLock::class)->release($job);
        }
    }

    private function snapshot(array $tables): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }
}
