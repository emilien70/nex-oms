<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Jobs\DiscoverAllegroReturnsJob;
use Modules\Integrations\Allegro\Jobs\SyncAllegroReturnJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroReturnImportService;
use Modules\Integrations\Allegro\Services\AllegroReturnNormalizer;
use Modules\Integrations\Allegro\Services\AllegroReturnSyncService;
use Modules\Products\Models\Product;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Tests\Support\AllegroOrderFixture;
use Tests\Support\AllegroReturnFixture as Fixture;
use Tests\TestCase;

class AllegroReturnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(12, 0));
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
        parent::tearDown();
    }

    public function test_disabled_by_default_and_settings_do_not_fetch_data(): void
    {
        $account = $this->account(false);
        $this->assertFalse($account->returns_enabled);
        $this->get(route('integrations.allegro.edit', ['tab' => 'returns']))->assertOk()->assertSee('Pobieranie zwrotów');
        app(AllegroReturnSyncService::class)->dispatchDue();
        Queue::assertNothingPushed();
        $this->put(route('integrations.allegro.returns.settings'), $this->settings($account))->assertRedirect();
        $this->assertTrue($account->fresh()->returns_enabled);
        $this->assertSame(1, $account->fresh()->returns_revision);
        $this->assertSame('production:seller-1', $account->fresh()->returns_identity);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_settings_reject_stale_identity_revision_future_date_and_disconnected_account(): void
    {
        $account = $this->account(false);
        foreach ([['identity' => 'sandbox:other'], ['configuration_revision' => 99], ['returns_from' => '2026-10-23']] as $change) {
            $this->put(route('integrations.allegro.returns.settings'), array_replace($this->settings($account), $change))->assertSessionHasErrors();
        }
        $account->update(['is_active' => false]);
        $this->put(route('integrations.allegro.returns.settings'), $this->settings($account))->assertSessionHasErrors('returns_enabled');
        $this->assertFalse($account->fresh()->returns_enabled);
    }

    public function test_import_is_idempotent_and_distinct_returns_for_order_are_preserved(): void
    {
        $account = $this->account();
        $first = $this->store($account);
        $this->store($account);
        $raw = Fixture::payload();
        $raw['id'] = (string) Str::uuid();
        $this->store($account, $raw);
        $this->assertDatabaseCount('order_returns', 2);
        $this->assertDatabaseCount('order_return_items', 2);
        $this->assertDatabaseCount('order_return_events', 2);
        $this->assertSame('60.02', $first->total_value);
        $this->assertNull($first->order_id);
        foreach (['orders', 'invoices', 'shipments', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertStringNotContainsString('PRIVATE-BANK', DB::table('order_returns')->get()->toJson());
    }

    public function test_order_is_linked_later_without_guessing_or_mutating_it(): void
    {
        $account = $this->account();
        $record = $this->store($account);
        [$order, $item] = $this->linkedOrder($account);
        $before = $order->fresh()->getAttributes();
        $record = $this->store($account);
        $this->assertSame($order->id, $record->order_id);
        $this->assertSame($item->id, $record->items()->sole()->order_item_id);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame('Testowa 1', $record->order_snapshot['street']);
        $item->replicate()->save();
        $this->store($account);
        $this->assertNull($record->items()->sole()->order_item_id);
    }

    public function test_identity_includes_environment_and_seller(): void
    {
        $account = $this->account();
        $this->store($account);
        $account->update(['environment' => 'sandbox']);
        $this->store($account);
        $account->update(['allegro_user_id' => 'other-seller']);
        $this->store($account);
        $this->assertDatabaseCount('order_returns', 3);
    }

    public function test_refresh_updates_status_parcels_and_only_observed_history(): void
    {
        $account = $this->account();
        $record = $this->store($account);
        $raw = Fixture::payload();
        $raw['status'] = 'FINISHED';
        $raw['parcels'] = [['waybill' => 'RETURN-1', 'carrierId' => 'INPOST', 'transportingWaybill' => 'RETURN-2', 'transportingCarrierId' => 'DHL']];
        Http::fake(['*/order/customer-returns/*' => Http::response($raw)]);
        $this->travel(10)->minutes();
        app(AllegroReturnSyncService::class)->refresh($record->id, 0);
        $this->assertSame('FINISHED', $record->fresh()->status);
        $this->assertSame('RETURN-2', $record->parcels()->sole()->transporting_waybill);
        $this->assertSame(2, $record->events()->count());
        $this->assertTrue($record->fresh()->status_observed_at->equalTo(now()));
        app(AllegroReturnSyncService::class)->refresh($record->id, 0);
        $this->assertSame(2, $record->events()->count());
        $record->update(['next_sync_at' => now()->subMinute()]);
        app(AllegroReturnSyncService::class)->dispatchDue();
        Queue::assertPushed(SyncAllegroReturnJob::class, fn ($job) => $job->returnId === $record->id);
    }

    public function test_beta_headers_oauth_refresh_and_safe_logs(): void
    {
        $account = $this->account();
        Http::fake([
            '*/auth/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => implode(' ', AllegroAccount::REQUIRED_SCOPES)]),
            '*/order/customer-returns*' => Http::sequence()->push([], 401)->push(['customerReturns' => [Fixture::payload()]]),
        ]);
        app(AllegroReturnSyncService::class)->discover($account->id, 0);
        $this->assertDatabaseCount('order_returns', 1);
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'GET') {
                $this->assertSame('application/vnd.allegro.beta.v1+json', $request->header('Accept')[0]);
            }
        }
        $logs = DB::table('integration_api_logs')->get()->toJson();
        foreach (['PRIVATE-BANK', 'buyer@example', 'Komentarz kupujacego', 'new-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function test_paginated_discovery_advances_only_after_atomic_valid_page(): void
    {
        $account = $this->account();
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $row = Fixture::payload();
            $row['id'] = (string) Str::uuid();
            $rows[] = $row;
        }
        $queries = [];
        Http::fake(function (Request $request) use ($rows, &$queries) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $queries[] = $query;

            return Http::response(['customerReturns' => count($queries) === 1 ? $rows : []]);
        });
        app(AllegroReturnSyncService::class)->discover($account->id, 0);
        $this->assertSame(100, $account->fresh()->returns_scan_offset);
        $until = $account->fresh()->returns_scan_until;
        $this->travel(2)->minutes();
        app(AllegroReturnSyncService::class)->discover($account->id, 0);
        $this->assertDatabaseCount('order_returns', 100);
        $this->assertSame('100', $queries[1]['offset']);
        $this->assertSame($queries[0]['createdAt_lte'], $queries[1]['createdAt_lte']);
        $this->assertNull($account->fresh()->returns_scan_until);
        $this->assertNotNull($account->fresh()->returns_last_scan_at);
    }

    public function test_bad_page_keeps_cursor_and_all_previously_saved_data(): void
    {
        $account = $this->account();
        $record = $this->store($account);
        $raw = Fixture::payload();
        $raw['status'] = 'DISPATCHED';
        Http::fake(['*' => Http::response(['customerReturns' => [$raw, ['id' => 'invalid']]])]);
        (new DiscoverAllegroReturnsJob($account->id, 0))->handle(app(AllegroReturnSyncService::class));
        $this->assertSame('CREATED', $record->fresh()->status);
        $this->assertSame(0, $account->fresh()->returns_scan_offset);
        $this->assertNotNull($account->fresh()->returns_error);
        $this->assertSame(1, $record->events()->count());
    }

    public function test_disable_while_request_runs_prevents_commit(): void
    {
        $account = $this->account();
        Http::fake(function () use ($account) {
            $account->update(['returns_enabled' => false, 'returns_revision' => 1]);

            return Http::response(['customerReturns' => [Fixture::payload()]]);
        });
        app(AllegroReturnSyncService::class)->discover($account->id, 0);
        $this->assertDatabaseCount('order_returns', 0);
    }

    public function test_wrong_response_identity_and_http_error_preserve_snapshot(): void
    {
        $account = $this->account();
        $record = $this->store($account);
        $raw = Fixture::payload();
        $raw['id'] = (string) Str::uuid();
        Http::fake(['*' => Http::response($raw)]);
        (new SyncAllegroReturnJob($record->id, 0))->handle(app(AllegroReturnSyncService::class));
        $this->assertSame('CREATED', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->sync_error);
        $this->assertDatabaseCount('order_returns', 1);
    }

    public function test_stale_jobs_and_other_identity_do_not_call_api(): void
    {
        $account = $this->account();
        $record = $this->store($account);
        app(AllegroReturnSyncService::class)->discover($account->id, 99);
        $account->update(['allegro_user_id' => 'different']);
        app(AllegroReturnSyncService::class)->refresh($record->id, 0);
        app(AllegroReturnSyncService::class)->dispatchDue();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_disconnect_disables_returns_without_removing_history(): void
    {
        $account = $this->account();
        $this->store($account);
        app(AllegroAccountService::class)->disconnect($account);
        $this->assertFalse($account->fresh()->returns_enabled);
        $this->assertGreaterThan(0, $account->fresh()->returns_revision);
        $this->assertDatabaseCount('order_returns', 1);
    }

    public function test_list_pagination_filters_search_and_literal_wildcard(): void
    {
        $account = $this->account();
        for ($i = 0; $i < 23; $i++) {
            $raw = Fixture::payload();
            $raw['id'] = (string) Str::uuid();
            $raw['referenceNumber'] = 'RET-'.$i;
            $this->store($account, $raw);
        }
        $response = $this->get(route('returns.index'))->assertOk()->assertSee('Wszystkie zwroty');
        $this->assertSame(20, $response->viewData('returns')->count());
        $this->assertSame(23, $response->viewData('returns')->total());
        $this->assertSame(3, $this->get(route('returns.index', ['page' => 2]))->assertOk()->viewData('returns')->count());
        $this->assertSame(0, $this->get(route('returns.index', ['q' => '%']))->assertOk()->viewData('returns')->total());
        $this->assertSame(23, $this->get(route('returns.index', ['status' => 'CREATED', 'from' => '2026-10-21', 'to' => '2026-10-21']))->assertOk()->viewData('returns')->total());
    }

    public function test_card_is_readonly_shows_original_and_return_parcels_and_escapes_content(): void
    {
        $account = $this->account();
        [$order] = $this->linkedOrder($account);
        $courier = CourierAccount::query()->create(['name' => 'Konto kurierskie', 'provider' => 'inpost', 'environment' => 'production', 'is_active' => true]);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'courier_account_id' => $courier->id, 'provider' => 'inpost', 'service' => 'inpost_locker_standard', 'status' => 'confirmed', 'oms_status' => 'delivered', 'tracking_number' => 'OUTBOUND-1', 'request_uuid' => (string) Str::uuid()]);
        $shipment->parcels()->create(['position' => 1, 'tracking_number' => 'OUTBOUND-2', 'weight' => '1.00', 'length' => '10', 'width' => '10', 'height' => '10']);
        $raw = Fixture::payload();
        $raw['items'][0]['reason']['userComment'] = '<script>alert("xss")</script>';
        $raw['parcels'] = [['waybill' => 'RETURN-1', 'carrierId' => 'INPOST']];
        $record = $this->store($account, $raw);
        $before = $order->fresh()->getAttributes();
        $response = $this->get(route('returns.show', $record))->assertOk()->assertSee('OUTBOUND-1')->assertSee('OUTBOUND-2')->assertSee('RETURN-1')
            ->assertSee('Przesyłki (zamówienie)')->assertSee('Zwrócono środki:')->assertSee('Brak danych')
            ->assertSee($raw['items'][0]['reason']['userComment'])->assertDontSee($raw['items'][0]['reason']['userComment'], false)
            ->assertDontSee('WYSTAW KOREKTĘ')->assertDontSee('PRIVATE-BANK');
        if ($directory = getenv('NEX_RETURNS_PREVIEW_DIR')) {
            $this->assertDirectoryExists($directory);
            file_put_contents($directory.'/return-card.html', $response->getContent());
            file_put_contents($directory.'/returns-list.html', $this->get(route('returns.index'))->assertOk()->getContent());
        }
        $this->get(route('orders.show', $order))->assertOk()->assertSee(route('returns.show', $record), false);
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_historical_data_survives_deleting_related_records(): void
    {
        $account = $this->account();
        [$order, $item] = $this->linkedOrder($account);
        $record = $this->store($account);
        $order->delete();
        $this->get(route('returns.show', $record))->assertOk()->assertSee('w koszu');
        $item->delete();
        $order->forceDelete();
        $account->delete();
        $record->refresh();
        $this->assertNull($record->order_id);
        $this->assertNull($record->allegro_account_id);
        $this->assertSame('Testowa 1', $record->order_snapshot['street']);
        $this->assertSame('Produkt zwracany', $record->items()->sole()->name);
        $this->assertNull($record->items()->sole()->order_item_id);
        $this->get(route('returns.show', $record))->assertOk()->assertSee('Testowa 1');
    }

    public function test_unknown_status_remains_visible(): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['status'] = 'NEW_API_STATUS';
        $record = $this->store($account, $raw);
        $this->get(route('returns.show', $record))->assertOk()->assertSee('Nieznany status: NEW_API_STATUS');
    }

    public function test_api_failure_does_not_clear_saved_data_or_expose_response(): void
    {
        $account = $this->account();
        $record = $this->store($account);
        Http::fake(['*' => Http::response(['errors' => [['message' => 'PRIVATE-BANK-ACCOUNT buyer@example.test']]], 503)]);
        (new SyncAllegroReturnJob($record->id, 0))->handle(app(AllegroReturnSyncService::class));
        $this->assertSame('CREATED', $record->fresh()->status);
        $this->assertSame('60.02', $record->fresh()->total_value);
        $this->assertNotNull($record->fresh()->sync_error);
        $this->assertStringNotContainsString('PRIVATE-BANK', DB::table('integration_api_logs')->get()->toJson());
    }

    public function test_reconnect_same_identity_preserves_settings_but_changed_seller_disables_them(): void
    {
        $account = $this->account();
        $this->store($account);
        app(AllegroAccountService::class)->markConnected($account, ['id' => 'seller-1', 'login' => 'seller']);
        $this->assertTrue($account->fresh()->returns_enabled);
        app(AllegroAccountService::class)->markConnected($account, ['id' => 'seller-2', 'login' => 'other']);
        $this->assertFalse($account->fresh()->returns_enabled);
        $this->assertDatabaseCount('order_returns', 1);
    }

    public function test_history_is_paginated_and_original_parcel_is_searchable(): void
    {
        $account = $this->account();
        [$order] = $this->linkedOrder($account);
        $record = $this->store($account);
        Shipment::query()->create(['order_id' => $order->id, 'provider' => 'inpost', 'service' => 'inpost_locker_standard',
            'status' => 'confirmed', 'tracking_number' => 'FIND-OUTBOUND', 'request_uuid' => (string) Str::uuid()]);
        for ($i = 0; $i < 25; $i++) {
            $record->events()->create(['type' => 'updated', 'status' => 'CREATED', 'observed_at' => now()]);
        }
        $this->assertSame(20, $this->get(route('returns.show', $record))->assertOk()->viewData('events')->count());
        $this->assertSame(6, $this->get(route('returns.show', [$record, 'history_page' => 2]))->assertOk()->viewData('events')->count());
        $this->assertSame(1, $this->get(route('returns.index', ['q' => 'FIND-OUTBOUND']))->assertOk()->viewData('returns')->total());
        $this->get(route('returns.index', ['to' => '2026-10-22']))->assertOk();
    }

    public function test_product_deletion_preserves_return_item_snapshot(): void
    {
        $account = $this->account();
        [$order, $item] = $this->linkedOrder($account);
        $product = Product::query()->create(['name' => 'Katalog', 'currency' => 'PLN', 'price_gross' => '30.01']);
        $item->update(['product_id' => $product->id]);
        $record = $this->store($account);
        $this->assertSame($product->id, $record->items()->sole()->product_id);
        $product->delete();
        $this->assertNull($record->items()->sole()->product_id);
        $this->assertSame('Produkt zwracany', $record->items()->sole()->name);
    }

    private function account(bool $enabled = true): AllegroAccount
    {
        return AllegroAccount::query()->create([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => 'seller-1', 'allegro_login' => 'seller', 'is_active' => true, 'last_test_status' => 'success',
            'returns_enabled' => $enabled, 'returns_from_at' => '2026-10-20 00:00:00',
            'returns_identity' => 'production:seller-1',
        ]);
    }

    private function settings(AllegroAccount $account): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->returns_revision,
            'returns_enabled' => 1, 'returns_from' => '2026-10-20'];
    }

    private function store(AllegroAccount $account, ?array $raw = null): OrderReturn
    {
        return app(AllegroReturnImportService::class)->store($account, app(AllegroReturnNormalizer::class)->normalize($raw ?? Fixture::payload()));
    }

    private function linkedOrder(AllegroAccount $account): array
    {
        $order = Order::query()->create(['source' => 'allegro', 'external_id' => AllegroOrderFixture::ORDER_ID,
            'status' => 'new', 'currency' => 'PLN', 'total_gross' => '90.03', 'paid_amount' => '90.03',
            'shipping_name' => 'Jan Testowy', 'shipping_street' => 'Testowa 1', 'shipping_city' => 'Krakow', 'shipping_country_code' => 'PL']);
        AllegroOrderLink::query()->create(['allegro_account_id' => $account->id, 'order_id' => $order->id,
            'environment' => 'production', 'seller_user_id' => 'seller-1', 'account_name' => 'multi-click',
            'external_order_id' => AllegroOrderFixture::ORDER_ID, 'external_status' => 'READY_FOR_PROCESSING',
            'source_hash' => hash('sha256', 'order'), 'imported_at' => now(), 'import_state' => 'complete']);
        $item = $order->items()->create(['offer_id' => '12345678901', 'product_name' => 'Nazwa historyczna', 'quantity' => 3,
            'unit_price_gross' => '30.01', 'total_price_gross' => '90.03', 'currency' => 'PLN']);

        return [$order, $item];
    }
}
