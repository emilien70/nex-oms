<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\OrderArchiveService;
use App\Support\OrderListNavigationContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\AllegroShipping\Jobs\RefreshAllegroShipmentJob;
use Modules\Integrations\DPD\Jobs\RefreshDpdShipmentJob;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfViewModelFactory;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderArchiveTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_only_old_non_trashed_orders_are_archived_and_restore_preserves_status_and_history(): void
    {
        $this->freezeSecond();
        $old = $this->order('Old', ['status' => Order::STATUS_SHIPPED, 'purchased_at' => now()->subDays(100)]);
        $oldManual = $this->order('Manual', ['created_at' => now()->subDays(92)]);
        $recent = $this->order('Recent', ['purchased_at' => now()->subDays(89), 'created_at' => now()->subDays(100)]);
        $boundary = $this->order('Boundary', ['purchased_at' => now()->subDays(90)]);
        $trash = $this->order('Trash', ['purchased_at' => now()->subDays(120)]);
        $trash->delete();
        $run = $old->automationRuns()->create([
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'chain_id' => (string) Str::uuid(),
            'depth' => 0,
            'status' => AutomationRun::STATUS_QUEUED,
            'event_payload' => ['order_id' => $old->id],
            'rule_snapshot' => [],
        ]);
        $service = app(OrderArchiveService::class);

        config(['order_archive.automatic' => false]);
        $this->assertSame(0, $service->archiveDue());
        config(['order_archive.automatic' => true]);
        $this->assertSame(2, $service->archiveDue());
        $this->assertTrue($old->fresh()->isArchived());
        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        $this->assertTrue($oldManual->fresh()->isArchived());
        $this->assertFalse($recent->fresh()->isArchived());
        $this->assertFalse($boundary->fresh()->isArchived());
        $this->assertFalse(Order::withTrashed()->findOrFail($trash->id)->isArchived());
        $this->assertSame(0, $service->archiveDue());

        Event::fake([OrderStatusChanged::class]);
        $this->post(route('orders.archive.restore', $old))->assertRedirect(route('orders.show', $old));
        $this->post(route('orders.archive.restore', $old))->assertRedirect(route('orders.show', $old));
        $restored = $old->fresh();
        $this->assertFalse($restored->isArchived());
        $this->assertSame(Order::STATUS_SHIPPED, $restored->status);
        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        $this->assertTrue($restored->archive_deferred_until->equalTo(now()->addDays(7)));
        $this->assertSame(['order_archived', 'order_unarchived'], $old->events()->oldest('id')->pluck('event_type')->all());
        Event::assertNotDispatched(OrderStatusChanged::class);
        $this->assertSame(0, $service->archiveDue());
        $this->travel(8)->days();
        $this->assertGreaterThanOrEqual(1, $service->archiveDue());
        $this->assertTrue($old->fresh()->isArchived());
    }

    public function test_archive_list_supports_selection_without_collecting_all_ids_or_exposing_active_order_actions(): void
    {
        $active = $this->order('Active Customer');
        $archived = $this->order('Archived Customer', ['archived_at' => now()]);
        $item = $archived->items()->create([
            'product_name' => 'Zgemma H8.2H',
            'quantity' => 1,
            'unit_price_gross' => '12.00',
            'total_price_gross' => '12.00',
            'currency' => 'PLN',
        ]);

        $this->get(route('orders.index', ['status' => 'new']))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$active->id])
            ->assertViewHas('statusCounts', fn ($counts) => $counts['new'] === 1);
        $this->get(route('orders.index', ['q' => 'Archived Customer']))->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->isEmpty());

        $query = ['product' => 'H8.2H', 'per_page' => 30];
        $list = $this->get(route('orders.archive.index', $query))->assertOk()
            ->assertSee('data-order-filter-scope="archive"', false)
            ->assertSee(route('orders.archive.bulk-delete'), false)
            ->assertSee('data-order-checkbox', false)
            ->assertSee('data-archive-force-delete disabled', false)
            ->assertSee('Faktury i korekty pozostaną dostępne w module Faktury.')
            ->assertDontSee('data-selection-action="select-all-pages"', false)
            ->assertDontSee('data-bulk-status-submit>', false)
            ->assertViewHas('allMatchingOrderIds', [])
            ->assertViewHas('starredMatchingOrderIds', [])
            ->assertViewHas('orders', fn ($orders) => $orders->perPage() === 30 && $orders->pluck('id')->all() === [$archived->id]);

        $card = $this->get(route('orders.show', OrderListNavigationContext::orderParameters($archived->id, $list->viewData('orderListContext'))))
            ->assertOk()->assertViewIs('orders.show')->assertSee('Zgemma H8.2H')
            ->assertSee('data-archived-order', false)
            ->assertSee('management-archive-control', false)
            ->assertSee('archivedOrderActionModal', false)
            ->assertSee('Nadaj przesyłkę')
            ->assertDontSee('data-order-state-url=', false)
            ->assertDontSee('data-order-email-form', false)
            ->assertDontSee('data-courier-form-url=', false);
        $this->assertSame(1, substr_count($card->getContent(), route('orders.archive.restore', $archived)));
        $this->assertSame(route('orders.archive.index', ['product' => 'H8.2H', 'page' => 1, 'per_page' => 30, 'list_return' => 1]), $card->viewData('ordersListUrl'));

        $this->patchJson(route('orders.star-color.update', $archived), ['star_color' => 'orange'])->assertConflict();
        $this->patchJson(route('orders.sections.shipping-address', $archived), ['shipping_city' => 'Inne miasto'])->assertConflict();
        $this->patchJson(route('orders.status.update', $archived), ['status' => 'shipped'])->assertConflict();
        $this->getJson(route('orders.shipments.form', [$archived, 'dpd']))->assertConflict();
        $this->deleteJson(route('orders.destroy', $archived))->assertConflict();
        $this->deleteJson(route('order-items.destroy', $item))->assertConflict();
        $this->postJson(route('orders.bulk-trash'), ['order_ids' => [$archived->id]])->assertSuccessful();
        $this->assertFalse($archived->fresh()->trashed());
        Http::assertNothingSent();
    }

    public function test_bulk_delete_keeps_documents_returns_payments_and_import_identity(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $item = $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());
        $documentBefore = app(InvoicePdfViewModelFactory::class)->make($invoice);
        $return = OrderReturn::query()->create([
            'source' => 'manual', 'creation_method' => 'manual', 'order_id' => $order->id,
            'currency' => 'PLN', 'total_value' => '100.00', 'submitted_at' => now(),
        ]);
        $link = AllegroOrderLink::query()->create([
            'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1',
            'account_name' => 'Allegro', 'external_order_id' => (string) Str::uuid(),
            'external_status' => 'READY_FOR_PROCESSING', 'source_hash' => str_repeat('a', 64), 'imported_at' => now(),
        ]);
        $shipment = $order->shipments()->create([
            'provider' => 'inpost_courier', 'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED, 'request_uuid' => (string) Str::uuid(),
        ]);
        $payment = $this->payNowPayment($order, 'CONFIRMED');
        $second = $this->order('Second', ['archived_at' => now()]);
        $order->forceFill(['archived_at' => now()])->save();
        $orderId = $order->id;
        $beforeDelete = $invoice->refresh()->getAttributes();
        unset($beforeDelete['order_id']);

        $this->postJson(route('orders.archive.bulk-delete'), [
            'confirm_archive_delete' => true, 'order_ids' => [$orderId, $second->id],
        ])->assertOk()->assertJsonPath('message', 'Usunięto trwale zamówienia z Archiwum: 2. Dokumenty sprzedaży pozostały w module Faktury.');

        $this->assertNull(Order::withTrashed()->find($orderId));
        $this->assertNull(Order::withTrashed()->find($second->id));
        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
        $invoice->refresh();
        $this->assertNull($invoice->order_id);
        $this->assertSame($beforeDelete, collect($invoice->getAttributes())->except('order_id')->all());
        $this->assertNull($invoice->items()->where('line_type', 'product')->sole()->order_item_id);
        $this->assertSame($documentBefore, app(InvoicePdfViewModelFactory::class)->make($invoice));
        $this->assertNull($return->fresh()->order_id);
        $this->assertNull($payment->fresh()->order_id);
        $this->assertNull($link->fresh()->order_id);
        $this->assertSame($link->external_order_id, $link->fresh()->external_order_id);
        $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
        Http::assertNothingSent();
    }

    public function test_bulk_delete_requires_confirmation_and_rejects_stale_or_duplicate_selections(): void
    {
        $archived = $this->order('Archived', ['archived_at' => now()]);
        $active = $this->order('Active');
        $trashed = $this->order('Trashed', ['archived_at' => now()]);
        $trashed->delete();
        $url = route('orders.archive.bulk-delete');

        $this->postJson($url, ['order_ids' => [$archived->id]])->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_archive_delete');
        foreach ([$active->id, $trashed->id, 999999, $archived->id] as $id) {
            $this->postJson($url, ['confirm_archive_delete' => true, 'order_ids' => [$archived->id, $id]])
                ->assertUnprocessable();
            $this->assertNotNull($archived->fresh());
        }
        $this->postJson($url, ['confirm_archive_delete' => true, 'order_ids' => range(1, OrderArchiveService::DELETE_LIMIT + 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('order_ids');
        $this->get($url)->assertMethodNotAllowed();
    }

    public function test_pending_operation_blocks_the_entire_batch_before_any_order_is_deleted(): void
    {
        $first = $this->order('First', ['archived_at' => now()]);
        $busy = $this->order('Busy', ['archived_at' => now()]);
        $payment = $this->payNowPayment($busy, 'PENDING');
        $url = route('orders.archive.bulk-delete');
        $selection = ['confirm_archive_delete' => true, 'order_ids' => [$first->id, $busy->id]];

        $this->postJson($url, $selection)->assertUnprocessable()->assertJsonValidationErrors('order_ids')->assertSee('PayNow');
        $this->assertNotNull($first->fresh());
        $this->assertNotNull($busy->fresh());

        $payment->update(['status' => 'CONFIRMED']);
        $this->postJson($url, $selection)->assertOk();
        $this->assertNull($payment->fresh()->order_id);
        Http::assertNothingSent();
    }

    public function test_queued_tracking_jobs_skip_archived_orders(): void
    {
        $archived = $this->order('Archived', ['archived_at' => now()]);
        $shipment = $archived->shipments()->create([
            'provider' => 'inpost_courier',
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'tracking_number' => 'ARCHIVE-TRACK-123',
            'status' => Shipment::STATUS_CONFIRMED,
            'request_uuid' => (string) Str::uuid(),
        ]);
        $drivers = \Mockery::mock(CourierDriverRegistry::class);
        $drivers->shouldNotReceive('forShipment');

        (new RefreshInPostShipmentJob($shipment))->handle($drivers);
        (new RefreshDpdShipmentJob($shipment))->handle($drivers);
        (new RefreshAllegroShipmentJob($shipment))->handle($drivers);
        $this->getJson(route('orders.scan', ['code' => 'ARCHIVE-TRACK-123']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_scheduled_tracking_skips_archived_orders_and_resumes_for_restored_orders(): void
    {
        $this->freezeSecond();
        $order = $this->order('In progress', ['status' => Order::STATUS_PENDING, 'purchased_at' => now()->subDays(100)]);
        $jobs = [
            'inpost-refresh-active-shipments' => [RefreshInPostShipmentJob::class, 'inpost_courier', Shipment::SERVICE_INPOST_COURIER_STANDARD],
            'dpd-refresh-active-shipments' => [RefreshDpdShipmentJob::class, 'dpd', Shipment::SERVICE_DPD_DOMESTIC],
            'allegro-shipping-refresh-active-shipments' => [RefreshAllegroShipmentJob::class, 'allegro_shipping', 'fixture-service'],
        ];
        $shipments = collect($jobs)->map(fn (array $job): Shipment => $order->shipments()->create([
            'provider' => $job[1], 'service' => $job[2], 'status' => Shipment::STATUS_CONFIRMED,
            'oms_status' => Shipment::OMS_STATUS_DISPATCHED, 'request_uuid' => (string) Str::uuid(),
            'external_id' => 'TRACK-'.$job[1], 'tracking_number' => 'BENCH-'.$job[1],
        ]));

        $events = collect(app(Schedule::class)->events())->keyBy('description');
        $archive = $events->get('archive-old-orders');
        $this->assertNotNull($archive);
        $this->assertSame('15 4 * * *', $archive->expression);
        $this->assertTrue($archive->withoutOverlapping);
        $archive->run(app());
        $this->assertFalse($order->fresh()->isArchived());

        config(['order_archive.automatic' => true]);
        $archive->run(app());
        $this->assertTrue($order->fresh()->isArchived());
        Queue::fake();
        foreach ($jobs as $name => [$job]) {
            $this->assertTrue($events->has($name));
            $events->get($name)->run(app());
            Queue::assertNotPushed($job);
        }

        $this->post(route('orders.archive.restore', $order))->assertRedirect(route('orders.show', $order));
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $archive->run(app());
        $this->assertFalse($order->fresh()->isArchived());
        foreach ($jobs as $name => [$job]) {
            $events->get($name)->run(app());
            Queue::assertPushed($job, fn ($refresh) => $refresh->shipment->is($shipments->get($name)));
        }
        Http::assertNothingSent();
    }

    public function test_archived_card_shows_saved_integrations_documents_and_shipment_without_refresh_actions(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());
        $order->forceFill(['archived_at' => now(), 'source' => 'allegro'])->save();
        $link = AllegroOrderLink::query()->create([
            'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1',
            'account_name' => 'Allegro', 'external_order_id' => (string) Str::uuid(),
            'external_status' => 'READY_FOR_PROCESSING', 'source_hash' => str_repeat('a', 64), 'imported_at' => now(),
            'buyer_message' => 'Zapisany komentarz', 'status_sync_state' => 'failed',
        ]);
        $shipment = $order->shipments()->create([
            'provider' => 'dpd', 'service' => Shipment::SERVICE_DPD_DOMESTIC, 'status' => '30103',
            'tracking_number' => 'ARCHIVED-DPD-123', 'request_uuid' => (string) Str::uuid(),
        ]);

        $page = $this->get(route('orders.show', $order))->assertOk()
            ->assertSeeText($invoice->number)
            ->assertSee(route('invoices.pdf', $invoice), false)
            ->assertSeeText('ARCHIVED-DPD-123')
            ->assertSeeText('Zapisany komentarz')
            ->assertSeeText('Prowizje i opłaty')
            ->assertDontSee('data-allegro-information-panel', false)
            ->assertDontSee(route('orders.allegro.status.retry', $order), false)
            ->assertDontSee(route('shipments.label', $shipment), false);
        $this->assertSame(1, substr_count($page->getContent(), route('orders.archive.restore', $order)));
        $this->assertNotNull($link->fresh());
        $this->postJson(route('orders.allegro-fees.refresh', $order))->assertConflict();
        $this->postJson(route('shipments.refresh', $shipment))->assertConflict();
        Http::assertNothingSent();
    }

    private function order(string $name, array $attributes = []): Order
    {
        return Order::unguarded(fn () => Order::query()->create($attributes + [
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'shipping_name' => $name,
            'currency' => 'PLN',
        ]));
    }

    private function payNowPayment(Order $order, string $status): PayNowPayment
    {
        return $order->payNowPayments()->create([
            'amount' => '100.00', 'currency' => 'PLN', 'external_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'status' => $status,
            'buyer_email_snapshot' => 'buyer@example.test', 'description' => 'Test',
        ]);
    }
}
