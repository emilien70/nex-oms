<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnRefundAllocation;
use App\Services\OrderReturnRefundService;
use App\Services\OrderReturnService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Jobs\DiscoverAllegroRefundsJob;
use Modules\Integrations\Allegro\Jobs\SyncAllegroRefundJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroRefundImportService;
use Modules\Integrations\Allegro\Services\AllegroRefundNormalizer;
use Modules\Integrations\Allegro\Services\AllegroRefundSyncService;
use Modules\Integrations\Allegro\Services\AllegroReturnImportService;
use Modules\Integrations\Allegro\Services\AllegroReturnNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroOrderFixture;
use Tests\Support\AllegroRefundFixture as Fixture;
use Tests\Support\AllegroReturnFixture;
use Tests\TestCase;

class AllegroRefundsTest extends TestCase
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

    public function test_import_is_idempotent_and_has_separate_read_only_history(): void
    {
        $account = $this->account();
        $refund = $this->store($account);
        $this->store($account);
        $this->assertSame('60.02', $refund->products_value);
        $this->assertSame('10.00', $refund->delivery_value);
        $this->assertDatabaseCount('allegro_payment_refunds', 1);
        $this->assertDatabaseCount('allegro_payment_refund_events', 1);
        $this->assertNull($refund->order_id);
        foreach (['orders', 'order_returns', 'invoices', 'shipments', 'order_events', 'order_return_refund_allocations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $raw = Fixture::payload();
        $raw['status'] = 'PARTIAL';
        $this->store($account, $raw);
        $this->assertDatabaseCount('allegro_payment_refund_events', 2);
        $this->assertSame(1, $refund->fresh()->lock_version);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_linking_is_exact_and_does_not_modify_order_payment_or_create_return(): void
    {
        $account = $this->account();
        $refund = $this->store($account);
        $order = $this->order($account);
        $before = $order->getAttributes();
        $refund = $this->store($account);
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('order_returns', 0);
        $raw = Fixture::payload();
        $raw['id'] = (string) Str::uuid();
        unset($raw['order']);
        $this->assertNull($this->store($account, $raw)->order_id);
        $account->update(['environment' => 'sandbox']);
        $this->assertNull($this->store($account)->order_id);
    }

    public function test_products_and_delivery_can_be_allocated_independently_and_cleared(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $before = $order->getAttributes();
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        $this->allocate($return, $refund, '60.02', '0.00')->assertRedirect();
        $summary = app(OrderReturnRefundService::class)->summary($return->fresh());
        $this->assertSame('60.02', $summary['products']);
        $this->assertSame('0.00', $summary['delivery']);
        $this->assertSame('success', $summary['tone']);
        $this->allocate($return->fresh(), $refund->fresh(), '60.02', '10.00')->assertRedirect();
        $this->assertSame('70.02', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->allocate($return->fresh(), $refund->fresh(), '0.00', '0.00')->assertRedirect();
        $this->assertDatabaseCount('order_return_refund_allocations', 1);
        $this->assertSame('secondary', app(OrderReturnRefundService::class)->summary($return->fresh())['tone']);
        $this->assertSame(3, $return->events()->where('type', 'refund_allocation_changed')->count());
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_delivery_is_not_available_if_it_was_not_refunded_in_allegro(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $raw = Fixture::payload();
        unset($raw['delivery']);
        $raw['totalValue']['amount'] = '60.02';
        $refund = $this->store($account, $raw);
        $this->allocate($return, $refund, '60.02', '0.01')->assertSessionHasErrors('refund_allocation');
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
    }

    public function test_same_money_cannot_be_allocated_to_two_returns_even_with_fresh_versions(): void
    {
        $account = $this->account();
        $one = $this->returnRecord($account);
        $two = $this->returnRecord($account, (string) Str::uuid());
        $refund = $this->store($account);
        $this->allocate($one, $refund, '30.01', '6.00')->assertRedirect();
        $this->allocate($two, $refund->fresh(), '30.01', '4.01')->assertSessionHasErrors('refund_allocation');
        $this->allocate($two, $refund->fresh(), '30.02', '0.00')->assertSessionHasErrors('refund_allocation');
        $this->allocate($two, $refund->fresh(), '30.01', '4.00')->assertRedirect();
        $this->assertDatabaseCount('order_return_refund_allocations', 2);
        $this->assertSame('10.00', (string) BigDecimal::of($one->refundAllocations()->sole()->delivery_value)->plus($two->refundAllocations()->sole()->delivery_value));
    }

    public function test_stale_forms_cannot_overwrite_allocation_or_duplicate_history(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        $this->allocate($return, $refund, '20.00', '0.00')->assertRedirect();
        $this->allocate($return, $refund, '40.00', '0.00')->assertSessionHasErrors('refund_allocation');
        $this->assertSame('20.00', $return->refundAllocations()->sole()->products_value);
        $this->assertSame(1, $return->events()->where('type', 'refund_allocation_changed')->count());
    }

    #[DataProvider('unconfirmedStatuses')]
    public function test_unconfirmed_refunds_never_become_confirmed_money(string $status): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $raw = Fixture::payload();
        $raw['status'] = $status;
        $refund = $this->store($account, $raw);
        $this->allocate($return, $refund, '10.00', '0.00')->assertSessionHasErrors('refund_allocation');
        $this->assertSame('0.00', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
    }

    public static function unconfirmedStatuses(): array
    {
        return array_map(fn ($s) => [$s], ['PARTIAL', 'WAITING', 'NEW', 'VALIDATED', 'VALIDATE_FAILED', 'IN_PROGRESS', 'CANCELED', 'FUTURE']);
    }

    public function test_actual_external_change_preserves_allocations_but_flags_invalid_totals(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        $this->allocate($return, $refund, '60.02', '0.00')->assertRedirect();
        $raw = Fixture::payload();
        $raw['totalValue']['amount'] = '30.00';
        $this->store($account, $raw);
        $summary = app(OrderReturnRefundService::class)->summary($return->fresh());
        $this->assertSame('warning', $summary['tone']);
        $this->assertSame('0.00', $summary['total']);
        $this->assertSame('60.02', $return->refundAllocations()->sole()->products_value);
        $this->assertDatabaseCount('allegro_payment_refund_events', 2);
    }

    public function test_wrong_order_seller_environment_currency_and_non_allegro_source_are_rejected(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        foreach (['external_order_id' => (string) Str::uuid(), 'seller_user_id' => 'other', 'environment' => 'sandbox', 'currency' => 'EUR'] as $field => $value) {
            $copy = $refund->replicate();
            $copy->external_refund_id = (string) Str::uuid();
            $copy->$field = $value;
            $copy->save();
            $this->allocate($return, $copy, '10.00', '0.00')->assertSessionHasErrors('refund_allocation');
        }
        $return->update(['source' => 'prestashop']);
        $this->allocate($return, $refund, '10.00', '0.00')->assertSessionHasErrors('refund_allocation');
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
    }

    public function test_history_and_amounts_survive_removal_of_order_and_account(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        $this->allocate($return, $refund, '60.02', '10.00')->assertRedirect();
        $order->forceDelete();
        $account->delete();
        $this->assertNull($refund->fresh()->order_id);
        $this->assertNull($refund->fresh()->allegro_account_id);
        $this->assertSame('70.02', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertSame(1, $refund->events()->count());
    }

    public function test_disabled_by_default_scope_is_optional_and_settings_do_not_call_api(): void
    {
        $account = $this->account(false);
        $this->assertFalse($account->refunds_enabled);
        app(AllegroRefundSyncService::class)->dispatchDue();
        Queue::assertNothingPushed();
        $this->put(route('integrations.allegro.refunds.settings'), $this->settings($account))->assertRedirect();
        $this->assertTrue($account->fresh()->refunds_enabled);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $account->update(['granted_scopes' => AllegroAccount::REQUIRED_SCOPES]);
        $this->assertTrue($account->fresh()->isOperational());
        $this->put(route('integrations.allegro.refunds.settings'), $this->settings($account->fresh()))->assertSessionHasErrors('refunds_enabled');
    }

    public function test_discovery_uses_public_get_and_atomic_cursor_without_mutating_orders(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $before = $order->getAttributes();
        Http::fake(['api.allegro.pl/payments/refunds*' => Http::response($this->page([Fixture::payload()]))]);
        app(AllegroRefundSyncService::class)->discover($account->id, 0);
        $this->assertDatabaseCount('allegro_payment_refunds', 1);
        $this->assertNotNull($account->fresh()->refunds_last_scan_at);
        $this->assertNull($account->fresh()->refunds_scan_until);
        $this->assertSame($before, $order->fresh()->getAttributes());
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Accept', 'application/vnd.allegro.public.v1+json'));
    }

    public function test_bad_page_never_commits_partial_results_or_moves_offset(): void
    {
        $account = $this->account();
        $bad = Fixture::payload();
        $bad['id'] = 'bad';
        Http::fake(['api.allegro.pl/payments/refunds*' => Http::response($this->page([Fixture::payload(), $bad]))]);
        (new DiscoverAllegroRefundsJob($account->id, 0))->handle(app(AllegroRefundSyncService::class));
        $this->assertDatabaseCount('allegro_payment_refunds', 0);
        $this->assertSame(0, $account->fresh()->refunds_scan_offset);
        $this->assertNotNull($account->fresh()->refunds_error);
        $this->assertNotNull($account->fresh()->refunds_retry_after);
        app(AllegroRefundSyncService::class)->dispatchDue();
        Queue::assertNotPushed(DiscoverAllegroRefundsJob::class);
    }

    public function test_in_flight_response_after_configuration_change_is_not_committed(): void
    {
        $account = $this->account();
        Http::fake(['api.allegro.pl/payments/refunds*' => function () use ($account) {
            $account->update(['refunds_revision' => 1, 'refunds_enabled' => false]);

            return Http::response($this->page([Fixture::payload()]));
        }]);
        app(AllegroRefundSyncService::class)->discover($account->id, 0);
        $this->assertDatabaseCount('allegro_payment_refunds', 0);
    }

    public function test_refresh_failure_preserves_confirmed_amount_and_identity_change_disables_only_new_sync(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        $this->allocate($return, $refund, '60.02', '10.00')->assertRedirect();
        Http::fake(['api.allegro.pl/payments/refunds*' => Http::response([], 500)]);
        (new SyncAllegroRefundJob($account->id, 0, $refund->id))->handle(app(AllegroRefundSyncService::class));
        $this->assertSame('70.02', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
        $this->assertSame('warning', app(OrderReturnRefundService::class)->summary($return->fresh())['tone']);
        app(AllegroAccountService::class)->markConnected($account, ['id' => 'other-seller', 'login' => 'other']);
        $this->assertFalse($account->fresh()->refunds_enabled);
        $this->assertDatabaseCount('allegro_payment_refunds', 1);
    }

    public function test_populated_views_show_separate_shipping_and_no_payment_execution_controls(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $return = $this->returnRecord($account);
        $raw = Fixture::payload();
        $raw['sellerComment'] = '<script>private()</script>';
        $refund = $this->store($account, $raw);
        $this->get(route('returns.show', $return))->assertOk()->assertSee('Historia płatności zwrotu')
            ->assertSee('Zapisz przypisanie')->assertSee('Dostawa')->assertSee('70,02')->assertSee('60,02')->assertSee('10,00')
            ->assertSee($raw['sellerComment'])->assertDontSee($raw['sellerComment'], false)->assertDontSee('Zwróć wpłatę');
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Zwroty wpłat Allegro')->assertSee(Fixture::ID);
        $this->get(route('integrations.allegro.edit', ['tab' => 'returns']))->assertOk()->assertSee('Pobieraj zwroty wpłat');
        $this->assertTrue($refund->fresh()->requested_at->equalTo('2026-10-21T11:00:00.292Z'));
        Http::assertNothingSent();
    }

    public function test_pending_refund_marks_order_context_without_assigning_its_money(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $raw = Fixture::payload();
        $raw['status'] = 'IN_PROGRESS';
        $this->store($account, $raw);
        $response = $this->get(route('returns.index'))->assertOk();
        $this->assertSame('warning', $response->viewData('refundIndicators')[$return->id]['tone']);
        $this->assertSame('0.00', $response->viewData('refundIndicators')[$return->id]['total']);
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
    }

    public function test_other_refund_cannot_over_settle_products_of_the_same_return(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $first = $this->store($account);
        $this->allocate($return, $first, '50.00', '0.00')->assertSessionHasNoErrors();
        $raw = Fixture::payload();
        $raw['id'] = (string) Str::uuid();
        $second = $this->store($account, $raw);
        $this->allocate($return->fresh(), $second, '10.03', '0.00')->assertSessionHasErrors('refund_allocation');
        $this->allocate($return->fresh(), $second, '10.02', '0.00')->assertSessionHasNoErrors();
        $this->assertSame('60.02', app(OrderReturnRefundService::class)->summary($return->fresh())['total']);
    }

    public function test_removing_return_preserves_historical_assignment_and_does_not_free_money(): void
    {
        $account = $this->account();
        $one = $this->returnRecord($account);
        $two = $this->returnRecord($account, (string) Str::uuid());
        $refund = $this->store($account);
        $this->allocate($one, $refund, '60.02', '10.00')->assertSessionHasNoErrors();
        $number = $one->id;
        $one->delete();
        $allocation = OrderReturnRefundAllocation::query()->sole();
        $this->assertNull($allocation->order_return_id);
        $this->assertSame($number, $allocation->return_number);
        $this->allocate($two, $refund->fresh(), '0.01', '0.00')->assertSessionHasErrors('refund_allocation');
    }

    public function test_settings_reject_stale_and_future_input_and_preserve_original_returns_configuration(): void
    {
        $account = $this->account(false);
        $account->update(['returns_enabled' => true, 'returns_revision' => 17]);
        foreach ([['identity' => 'sandbox:other'], ['configuration_revision' => 9], ['refunds_from' => '2026-10-23']] as $change) {
            $this->put(route('integrations.allegro.refunds.settings'), array_replace($this->settings($account), $change))->assertSessionHasErrors();
        }
        $this->assertFalse($account->fresh()->refunds_enabled);
        $this->assertTrue($account->fresh()->returns_enabled);
        $this->assertSame(17, $account->fresh()->returns_revision);
        Http::assertNothingSent();
    }

    public function test_pagination_traverses_complete_fixed_window_and_refreshes_known_terminal_records(): void
    {
        $account = $this->account();
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $row = Fixture::payload();
            $row['id'] = (string) Str::uuid();
            $rows[] = $row;
        }
        Http::fake(['api.allegro.pl/payments/refunds*' => function ($request) use ($rows) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['offset'] ?? 0) === 0 ? $rows : [Fixture::payload()];

            return Http::response(['refunds' => $page, 'count' => count($page), 'totalCount' => 101]);
        }]);
        $service = app(AllegroRefundSyncService::class);
        $service->discover($account->id, 0);
        $until = $account->fresh()->refunds_scan_until;
        $this->assertSame(100, $account->fresh()->refunds_scan_offset);
        $this->travel(1)->seconds();
        $service->discover($account->id, 0);
        $this->assertDatabaseCount('allegro_payment_refunds', 101);
        $this->assertNull($account->fresh()->refunds_scan_until);
        Http::assertSent(function ($request) use ($until): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['offset'] ?? '') === '100' && ($query['occurredAt_lte'] ?? '') === $until->utc()->toIso8601String();
        });
        $this->travel(6)->minutes();
        $service->dispatchDue();
        Queue::assertPushed(SyncAllegroRefundJob::class);
    }

    public function test_401_refresh_keeps_user_agent_and_logs_no_refund_payload(): void
    {
        $account = $this->account();
        Http::fake(['api.allegro.pl/payments/refunds*' => Http::sequence()->push([], 401)->push($this->page([Fixture::payload()])),
            'allegro.pl/auth/oauth/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => implode(' ', $account->granted_scopes)])]);
        app(AllegroRefundSyncService::class)->discover($account->id, 0);
        $this->assertDatabaseCount('allegro_payment_refunds', 1);
        Http::assertSentCount(3);
        $logs = DB::table('integration_api_logs')->get()->toJson();
        $this->assertStringNotContainsString(Fixture::ID, $logs);
        $this->assertStringNotContainsString('Rozliczenie zwrotu', $logs);
        $this->assertStringNotContainsString('new-access', $logs);
    }

    public function test_bad_amounts_and_missing_versions_are_rejected_without_partial_assignment(): void
    {
        $account = $this->account();
        $return = $this->returnRecord($account);
        $refund = $this->store($account);
        foreach (['-1.00', '1.001', 'bad', ''] as $amount) {
            $this->allocate($return, $refund, $amount, '0.00')->assertSessionHasErrors('products_value');
        }
        $this->patch(route('returns.refunds.allocate', [$return, $refund]), ['products_value' => '1.00', 'delivery_value' => '0.00'])->assertSessionHasErrors(['expected_refund_version', 'expected_return_version']);
        $this->assertDatabaseCount('order_return_refund_allocations', 0);
    }

    public function test_manual_return_keeps_refund_identity_after_removal_of_original_order(): void
    {
        $account = $this->account();
        $order = $this->order($account);
        $order->update(['customer_login' => 'buyer']);
        $item = $order->items()->create(['product_name' => 'Produkt', 'quantity' => 1, 'unit_price_gross' => '60.02', 'total_price_gross' => '60.02', 'currency' => 'PLN']);
        $service = app(OrderReturnService::class);
        $return = $service->create(['request_key' => (string) Str::uuid(), 'order_id' => $order->id,
            'order_fingerprint' => $service->fingerprint($order->fresh()), 'items' => [['order_item_id' => $item->id, 'quantity' => 1]]])->fresh();
        $refund = $this->store($account);
        $this->assertSame('manual', $return->creation_method);
        $this->assertSame('production', data_get($return->order_snapshot, 'allegro_identity.environment'));
        $this->assertNull($return->external_return_id);
        $order->forceDelete();
        $this->allocate($return->fresh(), $refund->fresh(), '60.02', '0.00')->assertSessionHasNoErrors();
        $this->assertSame('60.02', app(OrderReturnRefundService::class)->summary($return->fresh())['products']);
        $this->assertDatabaseCount('order_return_refund_allocations', 1);
    }

    private function account(bool $enabled = true): AllegroAccount
    {
        return AllegroAccount::query()->create(['account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => [...AllegroAccount::REQUIRED_SCOPES, AllegroAccount::PAYMENTS_READ_SCOPE],
            'allegro_user_id' => 'seller-1', 'is_active' => true, 'last_test_status' => 'success',
            'refunds_enabled' => $enabled, 'refunds_from_at' => '2026-10-20 00:00:00', 'refunds_identity' => 'production:seller-1']);
    }

    private function settings(AllegroAccount $account): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->refunds_revision, 'refunds_enabled' => 1, 'refunds_from' => '2026-10-20'];
    }

    private function store(AllegroAccount $account, ?array $raw = null): AllegroPaymentRefund
    {
        return app(AllegroRefundImportService::class)->store($account, app(AllegroRefundNormalizer::class)->normalize($raw ?? Fixture::payload()));
    }

    private function returnRecord(AllegroAccount $account, ?string $id = null): OrderReturn
    {
        $raw = AllegroReturnFixture::payload();
        if ($id) {
            $raw['id'] = $id;
        }

        return app(AllegroReturnImportService::class)->store($account, app(AllegroReturnNormalizer::class)->normalize($raw))->fresh();
    }

    private function order(AllegroAccount $account): Order
    {
        $order = Order::query()->create(['source' => 'allegro', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '70.02', 'paid_amount' => '70.02']);
        AllegroOrderLink::query()->create(['allegro_account_id' => $account->id, 'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1',
            'account_name' => 'multi-click', 'external_order_id' => AllegroOrderFixture::ORDER_ID, 'external_status' => 'READY_FOR_PROCESSING',
            'source_hash' => hash('sha256', 'order'), 'imported_at' => now(), 'import_state' => 'complete']);

        return $order->fresh();
    }

    private function allocate(OrderReturn $return, AllegroPaymentRefund $refund, string $products, string $delivery)
    {
        return $this->patch(route('returns.refunds.allocate', [$return, $refund]), ['expected_return_version' => $return->lock_version, 'expected_refund_version' => $refund->lock_version,
            'products_value' => $products, 'delivery_value' => $delivery]);
    }

    private function page(array $rows): array
    {
        return ['refunds' => $rows, 'count' => count($rows), 'totalCount' => count($rows)];
    }
}
