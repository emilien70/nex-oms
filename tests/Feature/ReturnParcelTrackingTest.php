<?php

namespace Tests\Feature;

use App\Events\ReturnParcelStatusChanged;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnParcel;
use App\Models\OrderReturnParcelEvent;
use App\Models\ReturnStatusSetting;
use App\Services\OrderReturnParcelService;
use App\Services\ReturnParcelTrackingService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\AutomationReturnContext;
use Modules\Automation\Services\AutomationRuleService;
use Modules\Automation\Services\AutomationRunner;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Shipments\Jobs\RefreshReturnParcelJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnParcelTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
    }

    public function test_inpost_history_is_cached_deduplicated_and_shared_between_order_and_return(): void
    {
        $parcel = $this->parcel();
        Http::fake(['*/v1/tracking/*' => Http::response($this->inpost($parcel), 200)]);
        $service = app(ReturnParcelTrackingService::class);
        $service->sync($parcel->id);
        $service->sync($parcel->id);
        $this->assertSame('delivered', $parcel->fresh()->statusCode());
        $this->assertSame('1 dzień', $parcel->fresh()->durationLabel());
        $this->assertDatabaseCount('order_return_parcel_events', 2);
        $this->assertSame(1, $parcel->orderReturn->events()->where('type', 'parcel_status_changed')->count());
        $this->assertSame('new', $parcel->orderReturn->fresh()->localStatus->code);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->get(route('orders.show', $parcel->orderReturn->order_id))->assertOk()->assertSee('Przesyłka zwrotna')->assertSee('is-delivered')->assertSee($parcel->waybill);
        $this->get(route('returns.show', $parcel->orderReturn))->assertOk()->assertSee('Przesyłka zwrotna')->assertSee('Dodaj przesyłkę zwrotną')->assertSee($parcel->waybill);
        $this->getJson(route('orders.state', $parcel->orderReturn->order_id).'?shipments_signature=old')
            ->assertOk()->assertJsonPath('shipments_count', 1)->assertJsonPath('order_id', $parcel->orderReturn->order_id)
            ->assertSee('data-return-parcel-id', false);
        $this->getJson(route('returns.parcels.history', [$parcel->orderReturn, $parcel]))->assertOk()->assertSee('delivered');
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidTracking')]
    public function test_invalid_responses_do_not_replace_saved_tracking(string $case): void
    {
        $parcel = $this->parcel();
        $this->current($parcel, 'dispatched', 'in_transit', '2026-11-01T11:00:00Z');
        $raw = $this->inpost($parcel);
        match ($case) {
            'other number' => $raw['tracking_number'] = 'other',
            'invalid date' => $raw['updated_at'] = '2026-02-30T11:00:00Z',
            'invalid history' => $raw['tracking_details'] = ['bad'],
            'invalid status' => $raw['status'] = [],
            'missing date' => $raw['updated_at'] = null,
        };
        Http::fake(['*' => Http::response($raw)]);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->assertSame('dispatched', $parcel->fresh()->statusCode());
        $this->assertNotNull($parcel->fresh()->tracking_error);
        $this->assertDatabaseCount('order_return_parcel_events', 1);
    }

    public static function invalidTracking(): array
    {
        return array_map(fn ($value) => [$value], ['other number', 'invalid date', 'invalid history', 'invalid status', 'missing date']);
    }

    public function test_stale_response_after_number_change_is_ignored(): void
    {
        $parcel = $this->parcel();
        Http::fake(function () use ($parcel) {
            $raw = $this->inpost($parcel);
            $parcel->update(['waybill' => str_repeat('2', 24)]);

            return Http::response($raw);
        });
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->assertNull($parcel->fresh()->statusCode());
        $this->assertDatabaseCount('order_return_parcel_events', 0);
    }

    public function test_older_history_does_not_downgrade_status_and_return_to_customer_is_not_delivery(): void
    {
        $parcel = $this->parcel();
        $this->current($parcel, 'out_for_delivery', 'out_for_delivery', '2026-11-02T11:00:00Z');
        Http::fake(['*' => Http::sequence()->push($this->inpost($parcel, 'in_transit', '2026-11-01T12:00:00Z'))
            ->push($this->inpost($parcel, 'returning_to_sender', '2026-11-02T12:00:00Z'))]);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->assertSame('out_for_delivery', $parcel->fresh()->statusCode());
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->assertSame('returned', $parcel->fresh()->statusCode());
        $this->assertSame('Wraca do klienta', $parcel->fresh()->statusLabel());
        $this->assertFalse($parcel->fresh()->isTerminal());
        $this->assertSame('new', $parcel->orderReturn->localStatus->code);
    }

    public function test_allegro_tracking_requires_exact_carrier_number_and_account_identity(): void
    {
        $parcel = $this->parcel();
        $account = AllegroAccount::query()->create(['account_key' => 'tracking', 'name' => 'Test', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => 'seller', 'is_active' => true, 'last_test_status' => 'success']);
        $parcel->orderReturn->update(['allegro_account_id' => $account->id, 'environment' => 'production', 'seller_user_id' => 'seller']);
        $parcel->update(['carrier_id' => 'DPD', 'waybill' => 'DPD123']);
        Http::fake(['*' => Http::response(['carrierId' => 'DPD', 'waybills' => [
            ['waybill' => 'wrong', 'trackingDetails' => ['statuses' => [['code' => 'DELIVERED', 'occurredAt' => '2026-11-02T10:00:00Z']]]],
            ['waybill' => 'DPD123', 'trackingDetails' => ['statuses' => [['code' => 'IN_TRANSIT', 'occurredAt' => '2026-11-02T10:00:00Z']]]],
        ]])]);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->assertSame('dispatched', $parcel->fresh()->statusCode());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/order/carriers/DPD/tracking')
            && $request['waybill'] === 'DPD123'
            && $request->hasHeader('User-Agent', 'NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)'));
        $account->update(['allegro_user_id' => 'different']);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        Http::assertSentCount(1);
        $this->assertNotNull($parcel->fresh()->tracking_error);
    }

    public function test_scheduler_uses_sync_queue_hourly_and_handles_manual_parcel_on_old_return(): void
    {
        $parcel = $this->parcel();
        $parcel->orderReturn->update(['submitted_at' => now()->subYear()]);
        app(ReturnParcelTrackingService::class)->dispatchDue();
        app(ReturnParcelTrackingService::class)->dispatchDue();
        Queue::assertPushed(RefreshReturnParcelJob::class, 1);
        Queue::assertPushed(RefreshReturnParcelJob::class, fn ($job) => $job->queue === 'shipments-sync' && $job->parcelId === $parcel->id);
        Http::fake(['*' => Http::response($this->inpost($parcel, 'in_transit', '2026-11-02T10:00:00Z'))]);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->travel(59)->minutes();
        app(ReturnParcelTrackingService::class)->dispatchDue();
        Queue::assertPushed(RefreshReturnParcelJob::class, 1);
        $this->travel(2)->minutes();
        app(ReturnParcelTrackingService::class)->dispatchDue();
        Queue::assertPushed(RefreshReturnParcelJob::class, 2);
    }

    public function test_manual_parcel_validation_locking_ownership_and_history_retention(): void
    {
        $parcel = $this->parcel();
        $record = $parcel->orderReturn;
        $payload = ['carrier_id' => 'INPOST', 'waybill' => str_repeat('2', 24), 'expected_lock_version' => $record->lock_version];
        $this->post(route('returns.parcels.store', $record), $payload)->assertRedirect();
        $this->post(route('returns.parcels.store', $record), $payload)->assertSessionHasErrors('parcel');
        $payload['expected_lock_version']++;
        $this->post(route('returns.parcels.store', $record), $payload)->assertSessionHasErrors('waybill');
        $payload['waybill'] = 'bad';
        $this->post(route('returns.parcels.store', $record), $payload)->assertSessionHasErrors('waybill');
        $other = $this->parcel();
        $payload['waybill'] = str_repeat('3', 24);
        $this->patch(route('returns.parcels.update', [$other->orderReturn, $parcel]), $payload)->assertNotFound();
        $this->get(route('returns.parcels.history', [$other->orderReturn, $parcel]))->assertNotFound();
        $event = $this->current($parcel, 'dispatched', 'in_transit', '2026-11-02T10:00:00Z');
        $payload['waybill'] = str_repeat('3', 24);
        $this->patch(route('returns.parcels.update', [$record, $parcel]), $payload)->assertRedirect();
        $this->assertNull($parcel->fresh()->statusCode());
        $parcel->delete();
        $this->assertNull($event->fresh()->order_return_parcel_id);
        $this->assertSame($record->id, $event->fresh()->order_return_id);
        Http::assertNothingSent();
    }

    public function test_import_reordering_preserves_tracking_and_manual_numbers(): void
    {
        $manual = $this->parcel();
        $record = $manual->orderReturn;
        $service = app(OrderReturnParcelService::class);
        $first = ['carrier_id' => 'DPD', 'waybill' => 'AAA'];
        $second = ['carrier_id' => 'DHL', 'waybill' => 'BBB'];
        $service->syncImported($record, [$first, $second]);
        $imported = $record->parcels()->where('waybill', 'AAA')->sole();
        $event = $this->current($imported, 'delivered', 'delivered', '2026-11-02T10:00:00Z');
        $service->syncImported($record, [$second, $first, $first]);
        $this->assertSame(3, $record->parcels()->count());
        $this->assertSame($imported->id, $record->parcels()->where('waybill', 'AAA')->sole()->id);
        $this->assertSame('delivered', $imported->fresh()->statusCode());
        $service->syncImported($record, [$second]);
        $this->assertNotNull($manual->fresh());
        $this->assertNull($event->fresh()->order_return_parcel_id);
    }

    #[DataProvider('scopes')]
    public function test_automation_changes_only_exact_return_and_honors_parcel_scope(string $scope, bool $expected): void
    {
        $parcel = $this->parcel();
        $record = $parcel->orderReturn;
        $otherReturn = $this->parcel($record->order_id)->orderReturn;
        $record->parcels()->create(['position' => 1, 'carrier_id' => 'INPOST', 'waybill' => str_repeat('2', 24)]);
        $event = $this->current($parcel, 'delivered', 'delivered', '2026-11-02T10:00:00Z');
        $payload = (new ReturnParcelStatusChanged($parcel->fresh()->load('orderReturn'), $event, null))->payload();
        $data = $this->rule($scope);
        app(AutomationRuleService::class)->create($data);
        app(AutomationEngine::class)->evaluate('return.parcel_status_changed', $payload);
        app(AutomationEngine::class)->evaluate('return.parcel_status_changed', $payload);
        $run = AutomationRun::query()->sole();
        app(AutomationRunner::class)->execute($run, 0);
        app(AutomationRunner::class)->execute($run->fresh(), 0);
        $this->assertSame($expected ? 'received' : 'new', $record->fresh()->localStatus->code);
        $this->assertSame('new', $otherReturn->fresh()->localStatus->code);
        $this->assertSame($record->id, $run->fresh()->order_return_id);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('new', $record->order->fresh()->status);
        $this->assertSame($expected ? 1 : 0, $record->events()->where('type', 'local_status_changed')->count());
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public static function scopes(): array
    {
        return [['all', false], ['any', true]];
    }

    public function test_all_delivered_automation_does_not_reopen_completed_or_rejected_return(): void
    {
        foreach (['new', 'completed', 'rejected'] as $status) {
            $parcel = $this->parcel();
            $record = $parcel->orderReturn;
            $record->update(['local_status_id' => ReturnStatusSetting::query()->where('code', $status)->sole()->id]);
            $event = $this->current($parcel, 'delivered', 'delivered', '2026-11-02T10:00:00Z');
            $payload = (new ReturnParcelStatusChanged($parcel->fresh()->load('orderReturn'), $event, null))->payload();
            app(AutomationRuleService::class)->create($this->rule('all'));
            app(AutomationEngine::class)->evaluate('return.parcel_status_changed', $payload);
            foreach (AutomationRun::query()->where('order_id', $record->order_id)->get() as $run) {
                app(AutomationRunner::class)->execute($run, 0);
            }
            $this->assertSame($status === 'new' ? 'received' : $status, $record->fresh()->localStatus->code);
        }
    }

    public function test_forged_stale_and_outbound_events_cannot_target_return(): void
    {
        $parcel = $this->parcel();
        $event = $this->current($parcel, 'delivered', 'delivered', '2026-11-02T10:00:00Z');
        $payload = (new ReturnParcelStatusChanged($parcel->fresh()->load('orderReturn'), $event, null))->payload();
        $context = app(AutomationReturnContext::class);
        foreach (['order_return_id' => 999, 'return_parcel_event_id' => 999, 'shipment_direction' => 'outbound', 'tracking_identity' => 'fake'] as $key => $value) {
            $this->assertNull($context->parcel($parcel->orderReturn->order, array_replace($payload, [$key => $value])));
        }
        $parcel->update(['tracking_status_at' => now()->addHour()]);
        $this->assertNull($context->parcel($parcel->orderReturn->order, $payload));
    }

    public function test_editor_uses_live_return_statuses_and_rejects_invalid_rule_context(): void
    {
        $custom = ReturnStatusSetting::query()->create(['code' => 'custom', 'name' => 'Kontrola paczki', 'color' => '#123456', 'sort_order' => 90]);
        $data = $this->rule('all');
        $data['actions'][0]['configuration']['return_status_id'] = $custom->id;
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertCreated();
        $this->get(route('orders.automatic-actions.index'))->assertOk()->assertSee('Kontrola paczki')->assertSee('Zmień status zwrotu');
        $data['trigger'] = 'shipment.status_changed';
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertUnprocessable();
        $data = $this->rule('unknown');
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_pending_event_retries_queue_failure_with_same_event_id(): void
    {
        $parcel = $this->parcel();
        $event = $this->current($parcel, 'delivered', 'delivered', '2026-11-02T10:00:00Z');
        $event->update(['automation_pending' => true]);
        $seen = [];
        Event::listen(ReturnParcelStatusChanged::class, function ($notification) use (&$seen): void {
            $seen[] = $notification->payload()['event_id'];
            if (count($seen) === 1) {
                throw new \RuntimeException('Test queue outage');
            }
        });
        // Execute after-commit listeners immediately for this isolated outbox test.
        app()->instance('db.transactions', new DatabaseTransactionsManager);
        try {
            app(ReturnParcelTrackingService::class)->dispatchPendingEvents();
            $this->fail('Expected queue failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Test queue outage', $exception->getMessage());
        }
        $this->assertTrue($event->fresh()->automation_pending);
        app(ReturnParcelTrackingService::class)->dispatchPendingEvents();
        $this->assertFalse($event->fresh()->automation_pending);
        $this->assertSame([$event->event_id, $event->event_id], $seen);
    }

    public function test_standalone_return_is_tracked_without_creating_order_or_automation(): void
    {
        $parcel = $this->parcel();
        $parcel->orderReturn->update(['order_id' => null]);
        Http::fake(['*' => Http::response($this->inpost($parcel))]);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $event = $parcel->trackingEvents()->where('tracking_status', 'delivered')->sole();
        app(AutomationRuleService::class)->create($this->rule('all'));
        app(AutomationEngine::class)->evaluate('return.parcel_status_changed', (new ReturnParcelStatusChanged($parcel->fresh()->load('orderReturn'), $event, null))->payload());
        $this->assertSame('delivered', $parcel->fresh()->statusCode());
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_microseconds_and_timezone_roundtrip_preserve_identity_and_schedule(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        $parcel = $this->parcel();
        $event = $this->current($parcel, 'delivered', 'delivered', '2026-11-02T11:30:00.123456+01:00');
        $this->assertSame('2026-11-02 10:30:00.123456', $event->fresh()->occurred_at->utc()->format('Y-m-d H:i:s.u'));
        $payload = (new ReturnParcelStatusChanged($parcel->fresh()->load('orderReturn'), $event->fresh(), null))->payload();
        $this->assertNotNull(app(AutomationReturnContext::class)->parcel($parcel->orderReturn->order, $payload));
    }

    public function test_unavailable_carrier_never_guesses_status_and_refresh_is_queued_not_inline(): void
    {
        $parcel = $this->parcel();
        $parcel->update(['carrier_id' => 'OTHER']);
        app(ReturnParcelTrackingService::class)->sync($parcel->id);
        $this->assertNull($parcel->fresh()->statusCode());
        $this->assertNotNull($parcel->fresh()->tracking_error);
        $this->post(route('returns.parcels.refresh', [$parcel->orderReturn, $parcel]))->assertRedirect();
        Queue::assertPushed(RefreshReturnParcelJob::class, 1);
        $this->get(route('returns.show', $parcel->orderReturn))->assertOk()->assertSee('Brak danych śledzenia');
        Http::assertNothingSent();
    }

    private function parcel(?int $orderId = null): OrderReturnParcel
    {
        $orderId ??= Order::query()->create(['source' => 'manual', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '20.00', 'paid_amount' => '0.00'])->id;
        $record = OrderReturn::query()->create(['order_id' => $orderId, 'source' => 'manual', 'creation_method' => 'manual', 'reference_number' => 'RET',
            'account_name' => 'Ręczne', 'currency' => 'PLN', 'total_value' => '20.00', 'submitted_at' => now(),
            'local_status_id' => ReturnStatusSetting::initial()->id, 'local_status_changed_at' => now()]);

        return $record->parcels()->create(['position' => 0, 'carrier_id' => 'INPOST', 'waybill' => str_repeat('1', 24), 'is_manual' => true, 'submitted_at' => now()]);
    }

    private function inpost(OrderReturnParcel $parcel, string $status = 'delivered', string $at = '2026-11-02T10:00:00Z'): array
    {
        return ['tracking_number' => $parcel->waybill, 'status' => $status, 'updated_at' => $at,
            'tracking_details' => [['status' => 'in_transit', 'datetime' => '2026-11-01T10:00:00Z'], ['status' => $status, 'datetime' => $at]]];
    }

    private function current(OrderReturnParcel $parcel, string $status, string $raw, string $date): OrderReturnParcelEvent
    {
        $parcel->update(['tracking_identity' => $parcel->identity(), 'tracking_status' => $status, 'provider_status' => $raw, 'tracking_status_at' => $date]);

        return $parcel->trackingEvents()->create(['order_return_id' => $parcel->order_return_id, 'tracking_identity' => $parcel->identity(),
            'event_key' => hash('sha256', (string) Str::uuid()), 'event_id' => (string) Str::uuid(),
            'tracking_status' => $status, 'provider_status' => $raw, 'occurred_at' => $date]);
    }

    private function rule(string $scope): array
    {
        return ['name' => 'Odebrany zwrot', 'trigger' => 'return.parcel_status_changed', 'is_active' => true,
            'conditions' => [['field' => 'return_parcel_status', 'operator' => 'equals', 'value' => 'delivered']],
            'actions' => [['type' => AutomationCatalog::ACTION_CHANGE_RETURN_STATUS, 'stop_on_error' => true,
                'configuration' => ['return_status_id' => ReturnStatusSetting::query()->where('code', 'received')->sole()->id, 'parcel_scope' => $scope]]]];
    }
}
