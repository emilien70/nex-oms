<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Services\AnalyticsFilterService;
use Modules\Integrations\Allegro\Jobs\DiscoverAllegroOrdersJob;
use Modules\Integrations\Allegro\Jobs\SyncAllegroOrderJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderImportEvent;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroOrderDiscoveryService;
use Modules\Integrations\Allegro\Services\AllegroOrderSyncService;
use Modules\Invoices\Services\InvoiceDocumentPreparationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroAutomaticOrderImportTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    private array $raw;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->raw = Fixture::payload();
    }

    public function test_incomplete_purchase_is_visible_and_completed_in_place_without_losing_notes(): void
    {
        $account = $this->account();
        $this->raw['status'] = 'BOUGHT';
        unset($this->raw['delivery'], $this->raw['invoice'], $this->raw['payment']);
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $id = $order->id;
        $this->assertSame('incomplete', $order->allegroOrderLink->import_state);
        $this->assertFalse($order->purchaseIsComplete());
        $this->assertNull($order->shipping_street);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertFalse($order->allegroOrderLink->delivery_cost_known);
        $order->update(['notes' => 'Notatka sprzedawcy', 'star_color' => 'green']);
        $this->get(route('orders.index'))->assertOk()->assertSee('order-indicator inactive', false)->assertSee('test-kupujacy');
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Zakup niepełny')->assertSee('Nieustalony');

        $this->raw = Fixture::payload();
        $this->raw['status'] = 'FILLED_IN';
        $this->sync($account);
        $this->assertNull($order->fresh()->shipping_street);
        $this->raw['status'] = 'READY_FOR_PROCESSING';
        $this->sync($account);
        $order->refresh();
        $this->assertSame($id, $order->id);
        $this->assertTrue($order->purchaseIsComplete());
        $this->assertSame('new', $order->status);
        $this->assertSame('Dostawcza 10/2', $order->shipping_street);
        $this->assertSame('100.03', $order->paid_amount);
        $this->assertSame('Notatka sprzedawcy', $order->notes);
        $this->assertSame('green', $order->star_color);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('allegro_order_payments', 2);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_order_completed')->count());
        $order->update(['shipping_street' => 'Reczna poprawka']);
        $this->sync($account);
        $this->raw['status'] = 'BOUGHT';
        $this->sync($account);
        $this->assertSame('Reczna poprawka', $order->fresh()->shipping_street);
        $this->assertDatabaseCount('allegro_order_payments', 2);
        $this->assertTrue($order->fresh()->purchaseIsComplete());
        Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', config('services.allegro.user_agent')));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(config('services.allegro.user_agent'), $request->header('User-Agent')[0]);
        }
        Queue::assertNothingPushed();
    }

    public function test_ready_cod_is_complete_but_not_paid_and_uses_orange_icon(): void
    {
        $account = $this->account();
        $this->raw['payment'] = ['id' => Fixture::PAYMENT_ID, 'type' => 'CASH_ON_DELIVERY', 'provider' => null, 'paidAmount' => null, 'finishedAt' => null];
        $this->raw['surcharges'] = [];
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $this->assertTrue($order->purchaseIsComplete());
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('0.00', $order->paid_amount);
        $this->get(route('orders.index'))->assertOk()->assertSee('order-indicator confirmed', false)->assertSee('order-indicator cod', false);
    }

    public function test_unsupported_ready_payment_is_visible_for_review_and_not_silently_skipped(): void
    {
        $account = $this->account();
        $this->raw['payment']['type'] = 'EXTENDED_TERM';
        $this->fakeOrder();
        $this->sync($account);
        $link = AllegroOrderLink::query()->sole();
        $this->assertSame('review', $link->import_state);
        $this->assertStringContainsString('EXTENDED_TERM', $link->processing_error);
        $this->assertFalse($link->order->purchaseIsComplete());
        $this->get(route('orders.index', ['completeness' => 'review']))->assertOk()->assertSee('test-kupujacy');
    }

    public function test_combined_purchase_preserves_old_order_and_links_to_replacement(): void
    {
        $account = $this->account();
        $this->raw['status'] = 'FILLED_IN';
        $this->fakeOrder();
        $this->sync($account);
        $old = Order::query()->sole();
        $newId = (string) Str::uuid();
        $this->raw = Fixture::payload();
        $this->raw['id'] = $newId;
        app(AllegroOrderSyncService::class)->sync($account, $newId);
        $old->refresh();
        $this->assertSame('cancelled', $old->status);
        $this->assertSame('superseded', $old->allegroOrderLink->import_state);
        $this->assertSame($newId, $old->allegroOrderLink->replacement->external_order_id);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertSame(1, $old->events()->where('event_type', 'allegro_purchase_combined')->count());
    }

    public function test_cancellation_keeps_payment_and_does_not_change_shipped_status(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $order->update(['status' => 'shipped']);
        $this->raw['status'] = 'CANCELLED';
        $this->sync($account);
        $this->sync($account);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('100.03', $order->fresh()->paid_amount);
        $this->assertSame('cancelled', $order->fresh()->allegroOrderLink->import_state);
        $this->assertNotNull($order->fresh()->allegroOrderLink->processing_error);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_order_cancelled')->count());
        $this->get(route('orders.index'))->assertOk()
            ->assertSee('class="order-indicator cancelled"', false)
            ->assertSee('>!!</span>', false)
            ->assertSee('Zamówienie anulowane w Allegro.')
            ->assertDontSee('>T</span>', false);
    }

    public function test_trash_and_deleted_tombstones_are_never_reimported(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $order->delete();
        $this->sync($account);
        $this->assertSame(0, Order::query()->count());
        $order->forceDelete();
        $this->sync($account);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('allegro_order_links', 1);
    }

    public function test_enabling_import_anchors_cursor_and_resume_does_not_reset_it(): void
    {
        $account = $this->account(['order_import_enabled' => false, 'order_import_started_at' => null, 'order_import_identity' => null]);
        Http::fake(['*/order/event-stats' => Http::response(['latestEvent' => ['id' => '12345', 'occurredAt' => now()->toIso8601String()]])]);
        $this->put(route('integrations.allegro.orders.automatic'), ['order_import_enabled' => 1])->assertRedirect();
        $this->assertSame('12345', $account->fresh()->order_event_cursor);
        $this->assertTrue($account->fresh()->order_import_enabled);
        $this->put(route('integrations.allegro.orders.automatic'), ['order_import_enabled' => 0])->assertRedirect();
        $this->put(route('integrations.allegro.orders.automatic'), ['order_import_enabled' => 1])->assertRedirect();
        $this->assertSame('12345', $account->fresh()->order_event_cursor);
        Http::assertSentCount(1);
    }

    public function test_discovery_records_durable_events_before_advancing_cursor_and_queues_jobs(): void
    {
        $account = $this->account(['order_event_cursor' => '100']);
        $event = $this->event('101');
        Http::fake(['*/order/events*' => Http::sequence()->push(['events' => [$event]])->push(['events' => []])]);
        app(AllegroOrderDiscoveryService::class)->discover($account->id);
        $this->assertSame('101', $account->fresh()->order_event_cursor);
        $this->assertDatabaseCount('allegro_order_import_events', 1);
        Queue::assertPushed(SyncAllegroOrderJob::class);
        app(AllegroOrderDiscoveryService::class)->discover($account->id);
        $this->assertDatabaseCount('allegro_order_import_events', 1);
    }

    public function test_successful_scan_clears_the_previous_worker_error(): void
    {
        $error = 'Call to undefined method '.AllegroAccount::class.'::orderImportIdentity()';
        $account = $this->account(['order_event_cursor' => '100', 'last_order_import_error' => $error]);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))
            ->assertOk()->assertSee($error);
        Http::assertNothingSent();

        Http::fake(['*/order/events*' => Http::response(['events' => []])]);
        (new DiscoverAllegroOrdersJob($account->id))->handle(app(AllegroOrderDiscoveryService::class));

        $account->refresh();
        $this->assertSame('production:12345678', $account->orderImportIdentity());
        $this->assertSame('100', $account->order_event_cursor);
        $this->assertNull($account->last_order_import_error);
        $this->assertNotNull($account->last_order_scan_at);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))
            ->assertOk()->assertDontSee($error);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', (string) config('services.allegro.user_agent')));
        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function test_malformed_journal_does_not_advance_cursor_or_partially_save_batch(): void
    {
        $account = $this->account(['order_event_cursor' => '100']);
        Http::fake(['*/order/events*' => Http::response(['events' => [$this->event('101'), ['id' => '102']]])]);
        (new DiscoverAllegroOrdersJob($account->id))->handle(app(AllegroOrderDiscoveryService::class));
        $this->assertSame('100', $account->fresh()->order_event_cursor);
        $this->assertNotNull($account->fresh()->last_order_import_error);
        $this->assertDatabaseCount('allegro_order_import_events', 0);
    }

    public function test_failure_is_visible_and_other_events_can_still_complete(): void
    {
        $account = $this->account();
        $event = AllegroOrderImportEvent::query()->create(['allegro_account_id' => $account->id, 'environment' => 'production', 'seller_user_id' => '12345678',
            'external_event_id' => '101', 'external_order_id' => Fixture::ORDER_ID, 'event_type' => 'BOUGHT', 'occurred_at' => now()]);
        Http::fake(['*' => Http::response([], 429)]);
        (new SyncAllegroOrderJob($event->id))->handle(app(AllegroOrderSyncService::class));
        $this->assertSame('failed', $event->fresh()->status);
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertNotNull($event->fresh()->next_attempt_at);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk()->assertSee('Przekroczono limit');
    }

    public function test_review_purchase_remains_visible_with_retry_in_import_history(): void
    {
        $account = $this->account();
        $this->raw['payment']['type'] = 'EXTENDED_TERM';
        $this->fakeOrder();
        $event = AllegroOrderImportEvent::query()->create(['allegro_account_id' => $account->id, 'environment' => 'production', 'seller_user_id' => '12345678',
            'external_event_id' => '102', 'external_order_id' => Fixture::ORDER_ID, 'event_type' => 'READY_FOR_PROCESSING', 'occurred_at' => now()]);
        (new SyncAllegroOrderJob($event->id))->handle(app(AllegroOrderSyncService::class));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('failed', $event->fresh()->status);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))->assertOk()->assertSee('Ponów pobranie');
        $this->raw = Fixture::payload();
        (new SyncAllegroOrderJob($event->id))->handle(app(AllegroOrderSyncService::class));
        $this->assertSame('processed', $event->fresh()->status);
        $this->assertTrue(Order::query()->sole()->purchaseIsComplete());
    }

    public function test_incomplete_order_blocks_document_and_shipment_creation(): void
    {
        $account = $this->account();
        $this->raw['status'] = 'BOUGHT';
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        try {
            app(InvoiceDocumentPreparationService::class)->forCreation($order, $this->createDocumentSeries(), $this->documentContext('2026-10-20 12:00:00'));
            $this->fail('Incomplete purchase allowed invoice creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        try {
            app(ShipmentCreationAttemptService::class)->begin($order, new CourierAccount, []);
            $this->fail('Incomplete purchase allowed shipment creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('shipment_creation_attempts', 0);
    }

    public function test_notes_remain_editable_but_source_data_and_addresses_are_protected(): void
    {
        $account = $this->account();
        $this->raw['status'] = 'FILLED_IN';
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $this->patch(route('orders.sections.order-info', $order), ['notes' => 'Uwagi', 'source' => 'manual', 'customer_login' => 'zmiana'])->assertRedirect();
        $this->assertSame('Uwagi', $order->fresh()->notes);
        $this->assertSame('allegro', $order->fresh()->source);
        $this->assertSame('test-kupujacy', $order->fresh()->customer_login);
        $this->patchJson(route('orders.sections.shipping-address', $order), ['shipping_name' => 'Inny'])->assertUnprocessable();
    }

    public function test_buyer_comment_is_escaped_and_appears_at_top_of_messages_with_conditional_icons(): void
    {
        $account = $this->account();
        $this->raw['messageToSeller'] = '<script>alert(1)</script>'."\n".'Drugi wiersz';
        $this->raw['buyer']['firstName'] = 'Jan';
        $this->raw['buyer']['lastName'] = 'Kupujacy';
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $this->get(route('orders.show', $order))->assertOk()->assertSeeInOrder(['Wymiana wiadomości', 'order-buyer-comment', 'Jan Kupujacy:', 'Komentarz do zamówienia.'], false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $page = $this->get(route('orders.index'))->assertOk();
        $page->assertSee('aria-label="Komentarz kupującego"', false)->assertDontSee('aria-label="Uwagi do zamówienia"', false)->assertDontSee('aria-label="Wystawiono fakturę VAT"', false);
        $order->update(['notes' => 'Notatka']);
        $this->get(route('orders.index'))->assertOk()->assertSee('aria-label="Uwagi do zamówienia"', false);
    }

    public function test_prestashop_has_green_completeness_and_all_three_primary_icons(): void
    {
        Order::query()->create(['source' => 'prestashop', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '10.00']);
        $this->get(route('orders.index'))->assertOk()->assertSee('order-indicator confirmed', false)->assertSee('aria-label="Płatność"', false)->assertSee('aria-label="Przesyłka"', false);
    }

    public function test_local_cancellation_does_not_display_an_allegro_cancellation_warning(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $this->sync($account);
        Order::query()->sole()->update(['status' => 'cancelled']);

        $this->get(route('orders.index'))->assertOk()
            ->assertSee('order-indicator confirmed', false)
            ->assertSee('>T</span>', false)
            ->assertDontSee('class="order-indicator cancelled"', false)
            ->assertDontSee('>!!</span>', false);
    }

    public function test_historical_allegro_cancellation_status_displays_the_warning_icon(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $order->allegroOrderLink()->update(['external_status' => 'CANCELLED']);

        $this->get(route('orders.index'))->assertOk()
            ->assertSee('class="order-indicator cancelled"', false)
            ->assertSee('>!!</span>', false)
            ->assertDontSee('>T</span>', false);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame('complete', $order->fresh()->allegroOrderLink->import_state);
    }

    public function test_invoice_icon_uses_issued_vat_document_and_snapshot_survives_cancellation(): void
    {
        $account = $this->account();
        $this->fakeOrder();
        $this->sync($account);
        $order = Order::query()->sole();
        $order->items()->update(['vat_rate' => '23.00']);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext('2026-10-20 12:00:00'));
        $snapshot = $invoice->buyer_snapshot;
        $this->get(route('orders.index'))->assertOk()
            ->assertSee('aria-label="Wystawiono fakturę VAT"', false)
            ->assertSee('class="invoice-lines" aria-hidden="true"', false)
            ->assertSee('class="invoice-currency" aria-hidden="true">$</span>', false);
        $this->raw['status'] = 'CANCELLED';
        $this->sync($account);
        $this->assertSame($snapshot, $invoice->fresh()->buyer_snapshot);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame('new', $order->fresh()->status);
        $this->get(route('orders.index'))->assertOk()
            ->assertSee('>!!</span>', false)
            ->assertSee('aria-label="Wystawiono fakturę VAT"', false)
            ->assertSee('aria-label="Płatność"', false)
            ->assertSee('aria-label="Przesyłka"', false);
    }

    public function test_cancelled_incomplete_purchase_is_not_resurrected_by_old_ready_event(): void
    {
        $account = $this->account();
        $this->raw['status'] = 'BOUGHT';
        $this->fakeOrder();
        $this->sync($account);
        $this->raw['status'] = 'CANCELLED';
        $this->sync($account);
        $this->raw = Fixture::payload();
        $this->sync($account);
        $order = Order::query()->sole();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('cancelled', $order->allegroOrderLink->import_state);
        $this->assertSame('0.00', $order->paid_amount);
        $this->get(route('orders.index'))->assertOk()
            ->assertSee('class="order-indicator cancelled"', false)
            ->assertSee('>!!</span>', false)
            ->assertDontSee('>T</span>', false);
    }

    public function test_expired_cursor_is_not_silently_reset_and_disabled_import_does_not_call_api(): void
    {
        $account = $this->account(['order_event_cursor' => '100', 'last_order_scan_at' => now()->subDays(61)]);
        (new DiscoverAllegroOrdersJob($account->id))->handle(app(AllegroOrderDiscoveryService::class));
        $this->assertSame('100', $account->fresh()->order_event_cursor);
        $this->assertNotNull($account->fresh()->last_order_import_error);
        Http::assertNothingSent();
        $account->update(['order_import_enabled' => false]);
        app(AllegroOrderDiscoveryService::class)->discover($account->id);
        Http::assertNothingSent();
    }

    public function test_changed_account_identity_cannot_process_stored_event_or_move_cursor(): void
    {
        $account = $this->account(['order_import_identity' => 'production:old-seller']);
        (new DiscoverAllegroOrdersJob($account->id))->handle(app(AllegroOrderDiscoveryService::class));
        $this->assertNotNull($account->fresh()->last_order_import_error);
        Http::assertNothingSent();
    }

    public function test_broken_initial_cursor_does_not_enable_import_or_fall_back_to_history(): void
    {
        $account = $this->account(['order_import_enabled' => false, 'order_import_identity' => null, 'order_import_started_at' => null]);
        Http::fake(['*' => Http::response(['latestEvent' => []])]);
        $this->put(route('integrations.allegro.orders.automatic'), ['order_import_enabled' => 1])->assertSessionHasErrors('order_import_enabled');
        $this->assertFalse($account->fresh()->order_import_enabled);
        $this->assertNull($account->fresh()->order_event_cursor);
    }

    public function test_historical_incomplete_purchases_require_preview_and_confirmation(): void
    {
        $this->account();
        $this->raw['status'] = 'FILLED_IN';
        Http::fake(['*/order/checkout-forms?*' => Http::response(['checkoutForms' => [$this->raw]])]);
        $this->post(route('integrations.allegro.orders.history.preview'), ['history_from' => '2026-10-19'])->assertRedirect()->assertSessionHas('allegro_history_preview');
        $this->assertDatabaseCount('orders', 0);
        $this->get(route('integrations.allegro.edit', ['tab' => 'orders']))
            ->assertOk()->assertSee('Znalezione zakupy:')->assertSee(Fixture::ORDER_ID);
        $this->post(route('integrations.allegro.orders.history.import'))->assertSessionHasErrors('confirm');
        $this->post(route('integrations.allegro.orders.history.import'), ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('allegro_order_import_events', 1);
        Queue::assertPushed(SyncAllegroOrderJob::class);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_stats_exclude_incomplete_and_superseded_purchases(): void
    {
        $account = $this->account();
        $this->raw['status'] = 'BOUGHT';
        $this->fakeOrder();
        $this->sync($account);
        $filters = app(AnalyticsFilterService::class);
        $state = $filters->resolve([]);
        $this->assertSame(0, $filters->orders($state)->count());
        $this->raw = Fixture::payload();
        $this->sync($account);
        $this->assertSame(1, $filters->orders($state)->count());
        AllegroOrderLink::query()->update(['import_state' => 'superseded']);
        $this->assertSame(0, $filters->orders($state)->count());
    }

    private function account(array $overrides = []): AllegroAccount
    {
        return AllegroAccount::query()->create(array_replace([
            'account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production', 'client_id' => 'client', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'allegro_login' => 'multi-click', 'is_active' => true, 'last_test_status' => 'success',
            'order_import_enabled' => true, 'order_import_identity' => 'production:12345678', 'order_import_started_at' => now()->subMinute(),
        ], $overrides));
    }

    private function event(string $id): array
    {
        return ['id' => $id, 'type' => 'BOUGHT', 'occurredAt' => now()->toIso8601String(), 'order' => ['seller' => ['id' => '12345678'], 'checkoutForm' => ['id' => Fixture::ORDER_ID]]];
    }

    private function fakeOrder(): void
    {
        Http::fake(fn () => Http::response($this->raw));
    }

    private function sync(AllegroAccount $account): void
    {
        app(AllegroOrderSyncService::class)->sync($account, Fixture::ORDER_ID);
    }
}
