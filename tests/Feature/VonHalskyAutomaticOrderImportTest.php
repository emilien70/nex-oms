<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\VonHalsky\Jobs\DiscoverVonHalskyOrdersJob;
use Modules\Integrations\VonHalsky\Jobs\ImportVonHalskyOrderJob;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderDiscoveryService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportDispatcher;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderProcessingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VonHalsky\CatalogFixtures;
use Tests\TestCase;

class VonHalskyAutomaticOrderImportTest extends TestCase
{
    use CatalogFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00', config('app.timezone')));
        Http::preventStrayRequests();
        Http::fake([]);
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        OrderStatusSetting::syncDefaults();
    }

    public function test_default_disabled_and_get_pages_never_create_accounts_or_dispatch_work(): void
    {
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertOk()
            ->assertSee('Automatyczne pobieranie')->assertSee('Sprawdź teraz')->assertSee('Brak historii pobierania.');
        $this->assertDatabaseCount('von_halsky_accounts', 0);
        $account = $this->account();
        $this->assertFalse($account->order_import_enabled);
        $this->assertSame(10, $account->order_import_interval_minutes);
        $this->assertSame(0, app(VonHalskyOrderImportDispatcher::class)->dispatchDue());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_saving_settings_requires_version_and_valid_connection_but_keeps_credentials(): void
    {
        $account = $this->account();
        $secret = DB::table('von_halsky_accounts')->value('client_secret');
        $this->post(route('integrations.von-halsky.automatic-import'), $this->settingsPayload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($account->fresh()->order_import_enabled);
        $this->assertSame(1, $account->fresh()->order_import_revision);
        $this->assertSame($secret, DB::table('von_halsky_accounts')->value('client_secret'));
        $this->post(route('integrations.von-halsky.automatic-import'), $this->settingsPayload())->assertSessionHasErrors('expected_lock_version');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_do_not_enable_import(array $override, string $field): void
    {
        $this->account();
        $this->post(route('integrations.von-halsky.automatic-import'), array_replace($this->settingsPayload(), $override))->assertSessionHasErrors($field);
        $this->assertFalse(VonHalskyAccount::query()->sole()->order_import_enabled);
    }

    public static function invalidSettings(): array
    {
        return [[['order_import_started_at' => null], 'order_import_started_at'],
            [['order_import_started_at' => '2027-01-01T10:00'], 'order_import_started_at'],
            [['order_import_interval_minutes' => 5], 'order_import_interval_minutes'],
            [['order_import_enabled' => 'yes'], 'order_import_enabled']];
    }

    public function test_inactive_account_cannot_enable_import(): void
    {
        $this->account(['is_active' => false]);
        $this->post(route('integrations.von-halsky.automatic-import'), $this->settingsPayload())->assertSessionHasErrors('order_import_enabled');
    }

    public function test_manual_scan_is_queued_without_enabling_scheduler_and_repeated_click_is_blocked(): void
    {
        $account = $this->automaticAccount(['order_import_enabled' => false]);
        $route = route('integrations.von-halsky.check-now');
        $this->post($route, ['expected_lock_version' => 0])->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(DiscoverVonHalskyOrdersJob::class, fn ($job) => $job->accountId === $account->id && $job->manual && $job->queue === 'integrations');
        $this->assertFalse($account->fresh()->order_import_enabled);
        $this->post($route, ['expected_lock_version' => 0])->assertSessionHasErrors('von_halsky_scan');
        Http::assertNothingSent();
    }

    public function test_scheduler_honors_interval_and_revision_and_works_without_extra_cron(): void
    {
        $account = $this->automaticAccount();
        $dispatcher = app(VonHalskyOrderImportDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertSame(0, $dispatcher->dispatchDue());
        $this->travel(11)->minutes();
        $this->assertSame(1, $dispatcher->dispatchDue());
        Queue::assertPushed(DiscoverVonHalskyOrdersJob::class, 2);
        $this->assertNotNull($account->fresh()->last_order_scan_requested_at);
        Http::assertNothingSent();
        $this->assertStringContainsString('von-halsky-import-orders', file_get_contents(base_path('routes/console.php')));
    }

    public function test_discovery_uses_repeated_query_keys_and_creates_one_durable_candidate_without_order(): void
    {
        $account = $this->automaticAccount();
        $this->fakeOrders();
        $this->discover($account);
        $this->discover($account);
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertNotNull($account->fresh()->order_import_cursor_at);
        Queue::assertPushed(ImportVonHalskyOrderJob::class);
        Http::assertSent(function (Request $request): bool {
            $query = parse_url($request->url(), PHP_URL_QUERY);

            return str_contains($query, 'paymentStatus=PAID&paymentStatus=NOT_PAID')
                && str_contains($query, 'sort=updatedAt&sort=createdAt') && ! str_contains($query, '%5B')
                && $request->method() === 'GET' && $request->header('Authorization') === ['Bearer TEST_TOKEN'];
        });
        $this->assertArrayNotHasKey('processing_token', VonHalskyOrderImportAttempt::query()->sole()->toArray());
    }

    public function test_absent_quantity_is_visible_for_confirmation_and_manual_import_closes_attention_item(): void
    {
        $account = $this->automaticAccount();
        $this->fakeOrders();
        $this->discover($account);
        $this->process($account);
        $attempt = VonHalskyOrderImportAttempt::query()->sole();
        $this->assertSame('needs_quantity', $attempt->status);
        $this->assertDatabaseCount('orders', 0);
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertOk()->assertSee('Wymagają uwagi (1)')->assertSee('Potwierdź ilości');
        $response = $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => 0, 'order_identifier' => 'PG76P11'])->assertOk();
        $hash = $response->viewData('orderPreview')['import']['source_hash'];
        $this->post(route('integrations.von-halsky.import'), ['order_identifier' => 'PG76P11', 'preview_hash' => $hash,
            'quantities' => [1], 'confirmed_quantities' => 1])->assertSessionHasNoErrors();
        $this->assertSame('imported', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->processing_token);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_api_quantity_imports_once_through_existing_catalog_with_standard_import_events(): void
    {
        $account = $this->automaticAccount(['use_linked_products' => true, 'use_catalog_product_names' => true, 'use_catalog_vat' => true]);
        $product = $this->product();
        $this->link($account, $product);
        $data = $this->sourceOrder();
        $data['orderLines'][0]['quantity'] = 1;
        $this->fakeOrders([$data]);
        $this->discover($account);
        Event::fake([OrderCreated::class, OrderDataCompleted::class, OrderPaymentRegistered::class]);
        $this->process($account);
        $this->process($account);
        $order = Order::query()->sole();
        $item = $order->items()->sole();
        $this->assertSame('Nazwa OMS', $item->product_name);
        $this->assertSame('8.00', $item->vat_rate);
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('195.00', $order->paid_amount);
        $this->assertSame('195.00', $order->total_gross);
        $this->assertSame('imported', VonHalskyOrderImportAttempt::query()->sole()->status);
        $this->assertSame('api', $order->vonHalskyOrderLink->quantities_snapshot[0]['source']);
        $this->assertDatabaseCount('order_events', 1);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
        Event::assertDispatchedTimes(OrderCreated::class, 1);
        Event::assertDispatchedTimes(OrderDataCompleted::class, 1);
        Event::assertNotDispatched(OrderPaymentRegistered::class);
        Mail::assertNothingSent();
        Http::assertSentCount(3);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_never_becomes_default_one(mixed $quantity): void
    {
        $account = $this->automaticAccount();
        $data = $this->sourceOrder();
        $data['orderLines'][0]['quantity'] = $quantity;
        $this->fakeOrders([$data]);
        $this->discover($account);
        $this->process($account);
        $this->assertSame('blocked', VonHalskyOrderImportAttempt::query()->sole()->status);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidQuantities(): array
    {
        return [[null], [0], [-1], [true], [1.5], ['1e0']];
    }

    #[DataProvider('nonImportableStates')]
    public function test_incomplete_cancelled_and_inconsistent_orders_are_not_imported(string $state, string $outcome): void
    {
        $account = $this->automaticAccount();
        $data = $this->sourceOrder();
        if ($state === 'no_payment') {
            $data['paymentDetails']['payments'] = [];
        } elseif ($state === 'bad_total') {
            $data['orderLines'][0]['quantity'] = 1;
            $data['finalPrice']['amount'] = 200;
        } else {
            $data['status'] = $state;
        }
        $this->fakeOrders([$data]);
        $this->discover($account);
        $this->process($account);
        $this->assertSame($outcome, VonHalskyOrderImportAttempt::query()->sole()->status);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function nonImportableStates(): array
    {
        return [['CREATED', 'waiting'], ['CANCELED', 'ignored'], ['CANCELLED', 'ignored'], ['REFUSED', 'ignored'], ['REJECTED', 'ignored'],
            ['no_payment', 'waiting'], ['bad_total', 'blocked']];
    }

    public function test_paginated_scan_discovers_all_rows_before_advancing_cursor(): void
    {
        $account = $this->automaticAccount();
        $rows = [];
        for ($i = 0; $i < 31; $i++) {
            $row = $this->sourceOrder();
            $row['id'] = 'ORDER-'.$i;
            $row['updatedAt'] = CarbonImmutable::parse($row['createdAt'])->addMinutes($i)->toISOString();
            $rows[] = $row;
        }
        $this->fakeOrders($rows);
        $this->discover($account);
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 31);
        $this->assertNotNull($account->fresh()->order_import_cursor_at);
        Http::assertSentCount(2);
    }

    public function test_mid_scan_failure_keeps_cursor_and_recovers_already_persisted_candidates(): void
    {
        $account = $this->automaticAccount();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [...$this->sourceOrder(), 'id' => 'ORDER-'.$i];
        }
        Http::fake(function (Request $request) use ($rows) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['offset'] ?? 0) == 0 ? Http::response(['data' => $rows, 'page' => ['limit' => 30, 'offset' => 0, 'total' => 31]]) : Http::response([], 503);
        });
        (new DiscoverVonHalskyOrdersJob($account->id, 0))->handle(app(VonHalskyOrderDiscoveryService::class));
        $this->assertNull($account->fresh()->order_import_cursor_at);
        $this->assertNotNull($account->fresh()->last_order_import_error);
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 30);
        Queue::assertPushed(ImportVonHalskyOrderJob::class, 30);
    }

    public function test_old_generation_and_disconnected_account_do_not_call_api_or_change_history(): void
    {
        $account = $this->automaticAccount();
        $attempt = $this->attempt($account);
        $account->update(['order_import_revision' => 1, 'order_import_enabled' => false]);
        (new DiscoverVonHalskyOrdersJob($account->id, 0))->handle(app(VonHalskyOrderDiscoveryService::class));
        (new ImportVonHalskyOrderJob($account->id, 0, $attempt->id))->handle(app(VonHalskyOrderProcessingService::class));
        $this->assertSame('pending', $attempt->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_connection_change_disables_polling_and_invalidates_queued_generation(): void
    {
        $account = $this->automaticAccount();
        app(VonHalskyAccountService::class)->disconnect(0);
        $this->assertFalse($account->fresh()->order_import_enabled);
        $this->assertSame(1, $account->fresh()->order_import_revision);
        (new DiscoverVonHalskyOrdersJob($account->id, 0))->handle(app(VonHalskyOrderDiscoveryService::class));
        Http::assertNothingSent();
    }

    public function test_expired_processing_lease_recovers_but_active_lease_does_not_run_twice(): void
    {
        $account = $this->automaticAccount();
        $attempt = $this->attempt($account, ['status' => 'processing', 'processing_token' => (string) Str::uuid(), 'processing_expires_at' => now()->addMinute()]);
        $this->fakeOrders();
        $this->process($account);
        Http::assertNothingSent();
        $attempt->update(['processing_expires_at' => now()->subSecond()]);
        $this->process($account);
        $this->assertSame('needs_quantity', $attempt->fresh()->status);
        $this->assertSame(1, $attempt->fresh()->attempts);
        Http::assertSentCount(1);
    }

    public function test_new_owner_of_lease_prevents_old_worker_from_creating_order_or_overwriting_result(): void
    {
        $account = $this->automaticAccount();
        $attempt = $this->attempt($account);
        $data = $this->sourceOrder();
        $data['orderLines'][0]['quantity'] = 1;
        $calls = 0;
        $newToken = (string) Str::uuid();
        Http::fake(function () use (&$calls, $attempt, $data, $newToken) {
            if (++$calls === 2) {
                $attempt->update(['processing_token' => $newToken]);
            }

            return Http::response($data);
        });
        $this->process($account);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($newToken, $attempt->fresh()->processing_token);
    }

    public function test_disabling_import_during_network_prevents_order_creation(): void
    {
        $account = $this->automaticAccount();
        $this->attempt($account);
        $data = $this->sourceOrder();
        $data['orderLines'][0]['quantity'] = 1;
        Http::fake(function () use ($account, $data) {
            $account->update(['order_import_revision' => 1, 'order_import_enabled' => false]);

            return Http::response($data);
        });
        $this->process($account);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transient_error_obeys_retry_after_and_does_not_retry_immediately(): void
    {
        $account = $this->automaticAccount();
        $attempt = $this->attempt($account);
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $this->process($account);
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertTrue($attempt->fresh()->next_attempt_at->equalTo(now()->addSeconds(120)));
        $this->process($account);
        Http::assertSentCount(1);
    }

    public function test_daily_reconciliation_uses_start_date_even_when_cursor_is_recent(): void
    {
        $account = $this->automaticAccount(['order_import_cursor_at' => now()->subMinutes(2), 'last_order_reconciliation_at' => now()->subDays(4)]);
        $this->fakeOrders([]);
        $this->discover($account);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), $account->order_import_started_at->utc()->toISOString()));
    }

    public function test_history_has_ten_unique_orders_and_attention_keeps_older_items_accessible(): void
    {
        $account = $this->automaticAccount();
        for ($i = 0; $i < 25; $i++) {
            $this->attempt($account, ['external_order_id' => 'ORDER-'.$i, 'status' => 'needs_quantity']);
        }
        $response = $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertOk();
        $this->assertCount(10, $response->viewData('recentImports'));
        $this->assertSame(25, $response->viewData('attentionImports')->total());
        $this->assertCount(20, $response->viewData('attentionImports'));
        $this->assertStringContainsString('/integrations/von-halsky?', $response->viewData('attentionImports')->url(2));
        $this->assertStringNotContainsString('/preview?', $response->viewData('attentionImports')->url(2));
        $next = $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders', 'attention_page' => 2]))->assertOk();
        $this->assertCount(5, $next->viewData('attentionImports'));
    }

    public function test_used_migration_cannot_be_rolled_back(): void
    {
        $this->attempt($this->automaticAccount());
        $migration = require database_path('migrations/2026_10_29_040000_add_von_halsky_order_discovery.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    #[DataProvider('invalidPages')]
    public function test_malformed_list_never_advances_cursor_or_creates_orders(string $path, mixed $value): void
    {
        $account = $this->automaticAccount();
        $response = ['page' => ['limit' => 30, 'offset' => 0, 'total' => 1], 'data' => [$this->sourceOrder()]];
        data_set($response, $path, $value);
        Http::fake(['*' => Http::response($response)]);
        (new DiscoverVonHalskyOrdersJob($account->id, 0))->handle(app(VonHalskyOrderDiscoveryService::class));
        $this->assertNull($account->fresh()->order_import_cursor_at);
        $this->assertNotNull($account->fresh()->last_order_import_error);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 0);
    }

    public static function invalidPages(): array
    {
        return [['page.limit', 100], ['page.offset', 1], ['page.total', '1'], ['data', false], ['data', []],
            ['data.0.organizationId', 'c8ec11ce-12ba-4fb7-be9a-249e679f4e67'], ['data.0.id', '../wrong'],
            ['data.0.createdAt', null], ['data.0.updatedAt', 'not a date']];
    }

    public function test_unchanged_fractional_source_timestamp_does_not_requeue_quantity_confirmation(): void
    {
        $account = $this->automaticAccount();
        $this->fakeOrders();
        $this->discover($account);
        $this->process($account);
        $account->update(['last_order_reconciliation_at' => null]);
        $this->discover($account->fresh());
        $this->assertSame('needs_quantity', VonHalskyOrderImportAttempt::query()->sole()->status);
        $this->assertSame('841000', VonHalskyOrderImportAttempt::query()->sole()->external_updated_at->format('u'));
    }

    public function test_changed_order_reuses_history_row_and_can_progress_from_waiting_to_quantity_confirmation(): void
    {
        $account = $this->automaticAccount();
        $row = [...$this->sourceOrder(), 'status' => 'CREATED'];
        $this->fakeOrders([$row]);
        $this->discover($account);
        $this->process($account);
        $attempt = VonHalskyOrderImportAttempt::query()->sole();
        $this->assertSame('waiting', $attempt->status);
        $row = [...$this->sourceOrder(), 'updatedAt' => now()->subMinutes(2)->utc()->toISOString()];
        $this->fakeOrders([$row]);
        $this->discover($account->fresh());
        $this->assertSame('pending', $attempt->fresh()->status);
        $this->process($account->fresh());
        $this->assertSame('needs_quantity', $attempt->fresh()->status);
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 1);
    }

    public function test_scan_does_not_import_orders_created_before_selected_start_or_advance_past_unstable_changes(): void
    {
        $account = $this->automaticAccount(['order_import_started_at' => now()->subHours(2)]);
        $old = [...$this->sourceOrder(), 'updatedAt' => now()->subHour()->utc()->toISOString()];
        $recent = [...$this->sourceOrder(), 'id' => 'RECENT', 'createdAt' => now()->subSeconds(30)->utc()->toISOString()];
        $this->fakeOrders([$old, $recent]);
        $this->discover($account);
        $this->assertDatabaseCount('von_halsky_order_import_attempts', 0);
        $this->assertTrue($account->fresh()->order_import_cursor_at->equalTo(now()->subMinute()));
    }

    public function test_existing_import_is_only_linked_to_history_and_never_overwritten(): void
    {
        $account = $this->automaticAccount();
        $this->fakeOrders();
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11')['import'];
        $order = app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', $preview['source_hash'], [1], true);
        $order->update(['notes' => 'Dane operatora']);
        $before = $order->fresh()->getAttributes();
        $items = $order->items()->get()->map->getAttributes()->all();
        $this->discover($account);
        $this->assertSame('imported', VonHalskyOrderImportAttempt::query()->sole()->status);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($items, $order->items()->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('order_events', 1);
        $this->assertDatabaseCount('orders', 1);
        Http::assertSentCount(3);
    }

    #[DataProvider('deletionModes')]
    public function test_deleted_order_remains_a_tombstone_and_is_not_recreated(bool $force): void
    {
        $account = $this->automaticAccount();
        $this->attempt($account);
        $this->fakeOrders();
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11')['import'];
        $order = app(VonHalskyOrderImportService::class)->import($account, 'PG76P11', $preview['source_hash'], [1], true);
        $force ? $order->forceDelete() : $order->delete();
        $this->discover($account);
        $this->process($account);
        $this->assertSame('deleted', VonHalskyOrderImportAttempt::query()->sole()->status);
        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseCount('von_halsky_order_links', 1);
        Http::assertSentCount(3);
    }

    public static function deletionModes(): array
    {
        return [[false], [true]];
    }

    public function test_attempt_from_another_identity_is_not_processed_and_account_deletion_preserves_history(): void
    {
        $account = $this->automaticAccount();
        $attempt = $this->attempt($account, ['environment' => 'stage']);
        $this->process($account);
        Http::assertNothingSent();
        $account->delete();
        $this->assertNull($attempt->fresh()->von_halsky_account_id);
        $this->assertSame('PG76P11', $attempt->fresh()->external_order_id);
    }

    public function test_dispatcher_is_safe_before_additive_migration_is_installed(): void
    {
        Schema::drop('von_halsky_order_import_attempts');
        $this->assertSame(0, app(VonHalskyOrderImportDispatcher::class)->dispatchDue());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_check_now_without_account_or_with_stale_form_is_rejected_without_network(): void
    {
        $this->post(route('integrations.von-halsky.check-now'), ['expected_lock_version' => 0])->assertSessionHasErrors('von_halsky_scan');
        $this->automaticAccount();
        $this->post(route('integrations.von-halsky.check-now'), ['expected_lock_version' => 99])->assertSessionHasErrors('von_halsky_scan');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_public_portal_host_cannot_reach_import_settings_or_manual_scan(): void
    {
        $this->automaticAccount();
        config(['app.url' => 'https://oms.example.test']);
        $settings = PortalSetting::query()->firstOrCreate(['singleton_key' => 'default']);
        $settings->forceFill(['customer_domain' => 'klient.example.test'])->save();
        $this->post('https://klient.example.test/integrations/von-halsky/check-now', ['expected_lock_version' => 0])->assertNotFound();
        $this->post('https://klient.example.test/integrations/von-halsky/automatic-import', $this->settingsPayload())->assertNotFound();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_both_new_post_actions_require_csrf_outside_test_bypass(): void
    {
        $this->app->instance('env', 'local');
        $this->post(route('integrations.von-halsky.check-now'), ['expected_lock_version' => 0])->assertStatus(419);
        $this->post(route('integrations.von-halsky.automatic-import'), $this->settingsPayload())->assertStatus(419);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_partial_schema_without_recorded_migration_cannot_dispatch_work(): void
    {
        $this->automaticAccount();
        DB::table('migrations')->where('migration', '2026_10_29_040000_add_von_halsky_order_discovery')->delete();
        $this->assertSame(0, app(VonHalskyOrderImportDispatcher::class)->dispatchDue());
        $this->post(route('integrations.von-halsky.automatic-import'), $this->settingsPayload())->assertSessionHasErrors('order_import_enabled');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function automaticAccount(array $attributes = []): VonHalskyAccount
    {
        return $this->account(array_replace(['order_import_enabled' => true, 'order_import_started_at' => now()->subDays(2)], $attributes));
    }

    private function attempt(VonHalskyAccount $account, array $attributes = []): VonHalskyOrderImportAttempt
    {
        return VonHalskyOrderImportAttempt::query()->create(array_replace([
            'von_halsky_account_id' => $account->id, 'environment' => $account->environment->value,
            'organization_id' => $account->organization_id, 'external_order_id' => 'PG76P11',
            'external_status' => 'ACCEPTED',
            'external_created_at' => CarbonImmutable::parse($this->sourceOrder()['createdAt'])->setTimezone(config('app.timezone')),
            'external_updated_at' => CarbonImmutable::parse($this->sourceOrder()['createdAt'])->setTimezone(config('app.timezone')),
            'discovered_at' => now(), 'last_seen_at' => now(), 'status' => 'pending',
        ], $attributes))->refresh();
    }

    private function fakeOrders(?array $rows = null): void
    {
        $rows ??= [$this->sourceOrder()];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($rows) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) ($query['offset'] ?? 0);
                $filtered = array_values(array_filter($rows, fn ($row) => CarbonImmutable::parse($row['updatedAt'] ?? $row['createdAt'])
                    ->gte(CarbonImmutable::parse($query['updatedAtGte']))));

                return Http::response(['page' => ['limit' => 30, 'offset' => $offset, 'total' => count($filtered)], 'data' => array_slice($filtered, $offset, 30)]);
            }

            return Http::response($rows[0] ?? $this->sourceOrder());
        });
    }

    private function discover(VonHalskyAccount $account): void
    {
        app(VonHalskyOrderDiscoveryService::class)->discover($account->id, (int) $account->order_import_revision);
    }

    private function process(VonHalskyAccount $account): void
    {
        app(VonHalskyOrderProcessingService::class)->process($account->id, (int) $account->order_import_revision, VonHalskyOrderImportAttempt::query()->sole()->id);
    }

    private function settingsPayload(): array
    {
        return ['expected_lock_version' => 0, 'order_import_enabled' => '1', 'order_import_interval_minutes' => 10, 'order_import_started_at' => '2026-10-27T10:00'];
    }
}
