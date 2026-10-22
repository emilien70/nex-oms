<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\ExportAllegroShipmentJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroShipmentExport;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroShipmentExportService;
use Modules\Integrations\Allegro\Services\AllegroShipmentSettingsService;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingSynchronizer;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\ShipmentDeletionService;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroShipmentExportTest extends TestCase
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
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
        parent::tearDown();
    }

    public function test_new_number_is_dispatched_after_commit_and_rollback_removes_outbox(): void
    {
        [$order, , , $shipment] = $this->linkedOrder();
        DB::beginTransaction();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        Queue::assertNotPushed(ExportAllegroShipmentJob::class);
        DB::commit();
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
        $this->assertDatabaseHas('allegro_shipment_exports', ['order_id' => $order->id, 'waybill' => 'NUMBER-1', 'state' => 'pending']);
        Queue::fake();
        DB::beginTransaction();
        $shipment->update(['tracking_number' => 'NUMBER-ROLLBACK']);
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('allegro_shipment_exports', ['waybill' => 'NUMBER-ROLLBACK']);
    }

    public function test_gets_remote_list_before_posting_correct_carrier_number_and_original_items(): void
    {
        [$order, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        $this->fakeApi();
        $this->runExport($export);
        $this->runExport($export);
        $requests = Http::recorded()->map(fn ($entry) => $entry[0]->method().' '.parse_url($entry[0]->url(), PHP_URL_PATH))->all();
        $this->assertSame(['GET /order/checkout-forms/'.Fixture::ORDER_ID, 'GET /order/checkout-forms/'.Fixture::ORDER_ID.'/shipments',
            'GET /order/carriers', 'POST /order/checkout-forms/'.Fixture::ORDER_ID.'/shipments'], $requests);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() === [
            'carrierId' => 'INPOST', 'waybill' => 'NUMBER-1', 'lineItems' => [['id' => Fixture::payload()['lineItems'][0]['id']]],
        ]);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame('remote-shipment', $export->fresh()->remote_id);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame('Numery seryjne', $order->fresh()->notes);
        $this->assertSame('100.03', $order->fresh()->paid_amount);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_shipment_exported')->count());
        $this->assertDatabaseHas('integration_api_logs', ['operation' => 'add_order_shipment', 'method' => 'POST']);
        $this->assertStringNotContainsString('buyer', DB::table('integration_api_logs')->where('operation', 'add_order_shipment')->get()->toJson());
    }

    public function test_already_existing_number_is_confirmed_without_post(): void
    {
        [, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $this->fakeApi([$this->remoteNumber('INPOST', 'NUMBER-1')]);
        $this->runExport(AllegroShipmentExport::query()->sole());
        Http::assertSentCount(2);
        $this->assertSame('synced', AllegroShipmentExport::query()->sole()->state);
    }

    public function test_status_gate_works_with_number_before_status_and_status_before_number(): void
    {
        [$order, $account, , $shipment] = $this->linkedOrder('status');
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $this->assertSame('waiting', AllegroShipmentExport::query()->sole()->state);
        Queue::assertNothingPushed();
        app(OrderStatusService::class)->change($order, 'pending', 'manual');
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
        $this->assertSame('pending', AllegroShipmentExport::query()->sole()->state);
        Queue::fake();
        $shipment->parcels()->create(['position' => 1, 'tracking_number' => 'NUMBER-2'] + $this->dimensions());
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
        $this->assertDatabaseCount('allegro_shipment_exports', 2);
        $this->assertSame($account->shipment_sync_status_setting_id, OrderStatusSetting::query()->where('status', 'pending')->value('id'));
    }

    public function test_leaving_gate_while_queued_waits_and_reentering_dispatches_fresh_token(): void
    {
        [$order, , , $shipment] = $this->linkedOrder('status');
        $order->update(['status' => 'pending']);
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        $old = new ExportAllegroShipmentJob($export->id, $export->attempt_token);
        app(OrderStatusService::class)->change($order, 'new');
        $old->handle(app(AllegroShipmentExportService::class));
        $this->assertSame('waiting', $export->fresh()->state);
        app(OrderStatusService::class)->change($order, 'pending');
        $this->assertSame('pending', $export->fresh()->state);
        $this->assertNotSame($old->token, $export->fresh()->attempt_token);
        $old->handle(app(AllegroShipmentExportService::class));
        Http::assertNothingSent();
    }

    public function test_late_parcel_numbers_are_captured_without_duplicates_or_history_backfill(): void
    {
        [, $account, , $shipment] = $this->linkedOrder('disabled');
        $shipment->update(['tracking_number' => 'OLD-NUMBER']);
        $parcel = $shipment->parcels()->create(['position' => 1, 'tracking_number' => 'OLD-NUMBER'] + $this->dimensions());
        $account->update(['shipment_sync_mode' => 'immediate']);
        $shipment->update(['content_description' => 'Regular refresh']);
        $parcel->update(['weight' => '1.000']);
        $this->assertDatabaseCount('allegro_shipment_exports', 0);
        $later = $shipment->parcels()->create(['position' => 2] + $this->dimensions());
        $later->update(['tracking_number' => 'NEW-NUMBER']);
        $later->update(['tracking_number' => 'NEW-NUMBER']);
        $this->assertDatabaseCount('allegro_shipment_exports', 1);
        $this->assertSame('NEW-NUMBER', AllegroShipmentExport::query()->sole()->waybill);
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
    }

    public function test_configuration_save_does_not_backfill_and_invalidates_pending_jobs(): void
    {
        [, $account, , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        Queue::fake();
        app(AllegroShipmentSettingsService::class)->update([
            'identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->shipment_sync_revision,
            'shipment_sync_mode' => 'immediate',
        ]);
        $this->runExport($export);
        $this->assertSame('skipped', $export->fresh()->state);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unsafe_local_states_do_not_create_exports_and_cod_is_allowed(): void
    {
        [$order, , $link, $shipment] = $this->linkedOrder();
        foreach (['incomplete', 'review', 'cancelled', 'superseded'] as $state) {
            $link->update(['import_state' => $state]);
            $shipment->update(['tracking_number' => 'NUMBER-'.$state]);
        }
        $this->assertDatabaseCount('allegro_shipment_exports', 0);
        $link->update(['import_state' => 'complete']);
        $order->update(['cash_on_delivery' => true, 'payment_status' => 'unpaid', 'paid_amount' => '0.00']);
        $shipment->update(['tracking_number' => 'COD-NUMBER']);
        $this->assertDatabaseCount('allegro_shipment_exports', 1);
    }

    public function test_source_account_seller_and_environment_are_rechecked_before_http(): void
    {
        [, $account, , $shipment] = $this->linkedOrder();
        $original = $account->getAttributes();
        foreach ([['shipment_sync_mode' => 'disabled'], ['environment' => 'sandbox'], ['allegro_user_id' => 'other'], ['is_active' => false]] as $i => $change) {
            $account->setRawAttributes($original)->save();
            $shipment->update(['tracking_number' => 'NUMBER-'.$i]);
            $export = AllegroShipmentExport::query()->latest('id')->firstOrFail();
            $account->update($change);
            $this->runExport($export);
            $this->assertSame('skipped', $export->fresh()->state);
        }
        Http::assertNothingSent();
    }

    public function test_replaced_number_and_cancelled_shipment_make_queued_jobs_stale(): void
    {
        [, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $first = AllegroShipmentExport::query()->sole();
        $shipment->update(['tracking_number' => 'NUMBER-2']);
        $this->runExport($first);
        $this->assertSame('skipped', $first->fresh()->state);
        $last = AllegroShipmentExport::query()->latest('id')->firstOrFail();
        $shipment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->runExport($last);
        $this->assertSame('skipped', $last->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_actual_remote_cancellation_is_imported_without_post_and_preserves_payment(): void
    {
        [$order, , $link, $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $raw = Fixture::payload();
        $raw['status'] = 'CANCELLED';
        Http::fake(['*' => Http::response($raw)]);
        $this->runExport(AllegroShipmentExport::query()->sole());
        $this->assertSame('cancelled', $link->fresh()->import_state);
        $this->assertSame('100.03', $order->fresh()->paid_amount);
        $this->assertSame('Numery seryjne', $order->fresh()->notes);
        Http::assertSentCount(1);
    }

    public function test_timeout_after_post_rechecks_and_confirms_without_duplicate_post(): void
    {
        [, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        $posted = false;
        Http::fake(function (Request $request) use (&$posted) {
            if ($request->method() === 'POST') {
                $posted = true;

                return Http::failedConnection();
            }
            if (str_ends_with($request->url(), '/shipments')) {
                return Http::response(['shipments' => $posted ? [$this->remoteNumber('INPOST', 'NUMBER-1')] : []]);
            }

            return Http::response(str_ends_with($request->url(), '/carriers') ? ['carriers' => [['id' => 'INPOST']]] : Fixture::payload());
        });
        try {
            $this->runExport($export);
            $this->fail('A timeout must reach the queue.');
        } catch (AllegroApiException $exception) {
            $this->assertNull($exception->statusCode);
        }
        $this->runExport($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'));
    }

    public function test_permanent_error_is_separate_from_transport_state_and_can_be_retried_once(): void
    {
        [$order, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $this->fakeApi([], 422);
        $export = AllegroShipmentExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('failed', $export->fresh()->state);
        $this->assertNull($shipment->fresh()->error_message);
        $this->assertSame('confirmed', $shipment->fresh()->status);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Błąd przekazania')->assertSee('Ponów przekazanie numeru');
        Queue::fake();
        $url = route('orders.allegro.shipments.retry', ['order' => $order, 'export' => $export]);
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url)->assertSessionHasErrors('allegro_shipment_export');
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
    }

    public function test_invalid_retry_preserves_error_and_cannot_access_other_order(): void
    {
        [$order, $account, , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        (new ExportAllegroShipmentJob($export->id, $export->attempt_token))->failed(new \RuntimeException('private details'));
        $error = $export->fresh()->error;
        $account->update(['shipment_sync_mode' => 'disabled']);
        Queue::fake();
        $this->post(route('orders.allegro.shipments.retry', ['order' => $order, 'export' => $export]))->assertSessionHasErrors('allegro_shipment_export');
        $this->assertSame($error, $export->fresh()->error);
        $other = Order::query()->create(['source' => 'manual', 'status' => 'new', 'currency' => 'PLN', 'total_gross' => '0.00', 'payment_status' => 'unpaid']);
        $this->post(route('orders.allegro.shipments.retry', ['order' => $other, 'export' => $export]))->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_wysylam_keeps_all_aliases_and_only_verifies_automatic_assignment(): void
    {
        [, , , $shipment] = $this->linkedOrder('immediate', CourierAccount::PROVIDER_ALLEGRO_SHIPPING);
        $shipment->parcels()->create(['position' => 1] + $this->dimensions());
        app(AllegroShippingSynchronizer::class)->applyDetails($shipment, [
            'id' => 'shipment-remote', 'packages' => [['transportingInfo' => [
                ['carrierId' => 'INPOST', 'carrierWaybill' => 'INPOST-ALIAS'], ['carrierId' => 'ALLEGRO', 'carrierWaybill' => 'A12345'],
                ['carrierId' => 'DPD', 'carrierWaybill' => ''],
            ]]],
        ]);
        $this->assertSame('ALLEGRO', $shipment->fresh()->carrier_code);
        $this->assertSame('A12345', $shipment->fresh()->tracking_number);
        $this->assertCount(2, $shipment->parcels()->firstOrFail()->tracking_numbers);
        $this->assertDatabaseCount('allegro_shipment_exports', 2);
        $this->fakeApi([$this->remoteNumber('ALLEGRO', 'A12345')]);
        foreach (AllegroShipmentExport::query()->get() as $export) {
            $this->runExport($export);
            $this->assertSame('synced', $export->fresh()->state);
            $this->assertSame('Potwierdzono paczkę w Allegro', $export->fresh()->stateLabel());
        }
        $this->assertDatabaseHas('order_events', ['event_type' => 'allegro_shipment_exported', 'payload->confirmed_waybill' => 'A12345']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_missing_automatic_assignment_is_retried_but_never_added_manually(): void
    {
        [, , , $shipment] = $this->linkedOrder('immediate', CourierAccount::PROVIDER_ALLEGRO_SHIPPING);
        $shipment->update(['carrier_code' => 'ALLEGRO', 'tracking_number' => 'A12345']);
        $export = AllegroShipmentExport::query()->sole();
        $this->fakeApi();
        try {
            $this->runExport($export);
            $this->fail('Automatic assignment must be rechecked.');
        } catch (AllegroApiException $exception) {
            $this->assertSame(409, $exception->statusCode);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_removing_shipment_preserves_export_and_history_and_stops_pending_job(): void
    {
        [$order, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        $this->fakeApi();
        $this->runExport($export);
        app(ShipmentDeletionService::class)->delete($shipment);
        $this->assertNull($export->fresh()->shipment_id);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_shipment_exported')->count());
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_changed_configuration_during_post_does_not_lose_real_acknowledgement(): void
    {
        [, $account, , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        Http::fake(function (Request $request) use ($account) {
            if ($request->method() === 'POST') {
                app(AllegroShipmentSettingsService::class)->update([
                    'identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->shipment_sync_revision, 'shipment_sync_mode' => 'disabled',
                ]);

                return Http::response($this->remoteNumber('INPOST', 'NUMBER-1'), 201);
            }
            if (str_ends_with($request->url(), '/shipments')) {
                return Http::response(['shipments' => []]);
            }

            return Http::response(str_ends_with($request->url(), '/carriers') ? ['carriers' => [['id' => 'INPOST']]] : Fixture::payload());
        });
        $export = AllegroShipmentExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertNotNull($export->fresh()->confirmed_at);
    }

    public function test_post_after_401_keeps_body_user_agent_and_uses_refreshed_token(): void
    {
        [, $account, , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $posts = 0;
        Http::fake(function (Request $request) use (&$posts) {
            if (str_contains($request->url(), '/auth/oauth/token')) {
                return Http::response(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 43200]);
            }
            if ($request->method() === 'POST') {
                return ++$posts === 1 ? Http::response([], 401) : Http::response($this->remoteNumber('INPOST', 'NUMBER-1'), 201);
            }
            if (str_ends_with($request->url(), '/shipments')) {
                return Http::response(['shipments' => []]);
            }

            return Http::response(str_ends_with($request->url(), '/carriers') ? ['carriers' => [['id' => 'INPOST']]] : Fixture::payload());
        });
        $this->runExport(AllegroShipmentExport::query()->sole());
        $this->assertSame(2, $posts);
        $this->assertSame('rotated-access', $account->fresh()->access_token);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer rotated-access')
            && $request['waybill'] === 'NUMBER-1' && $request['carrierId'] === 'INPOST');
    }

    public function test_unsafe_remote_payloads_are_blocked_without_post(): void
    {
        [, , , $shipment] = $this->linkedOrder();
        $raw = [];
        Http::fake(function () use (&$raw) {
            return Http::response($raw);
        });
        foreach ([['status' => 'FILLED_IN'], ['id' => 'other'], ['lineItems' => []],
            ['fulfillment' => ['provider' => ['id' => 'ALLEGRO'], 'status' => 'NEW']],
            ['fulfillment' => ['provider' => ['id' => 'SELLER'], 'status' => 'CANCELLED']]] as $i => $change) {
            $raw = array_replace(Fixture::payload(), $change);
            $shipment->update(['tracking_number' => 'NUMBER-'.$i]);
            $export = AllegroShipmentExport::query()->latest('id')->firstOrFail();
            $this->runExport($export);
            $this->assertSame('failed', $export->fresh()->state);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_status_change_during_preflight_prevents_post(): void
    {
        [$order, , , $shipment] = $this->linkedOrder('status');
        $order->update(['status' => 'pending']);
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        Http::fake(function (Request $request) use ($order) {
            if (str_ends_with($request->url(), '/shipments')) {
                $order->update(['status' => 'new']);

                return Http::response(['shipments' => []]);
            }

            return Http::response(Fixture::payload());
        });
        $export = AllegroShipmentExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('waiting', $export->fresh()->state);
        Http::assertSentCount(2);
    }

    public function test_transient_errors_retry_and_exhausted_failure_is_recorded_once(): void
    {
        [$order, , , $shipment] = $this->linkedOrder();
        $code = 429;
        Http::fake(function () use (&$code) {
            return Http::response([], $code);
        });
        foreach ([429, 503] as $code) {
            $shipment->update(['tracking_number' => 'NUMBER-'.$code]);
            $export = AllegroShipmentExport::query()->latest('id')->firstOrFail();
            try {
                $this->runExport($export);
                $this->fail('Transient API errors must be retried.');
            } catch (AllegroApiException $exception) {
                $this->assertSame($code, $exception->statusCode);
            }
            $this->assertSame('pending', $export->fresh()->state);
        }
        $job = new ExportAllegroShipmentJob($export->id, $export->attempt_token);
        $job->failed(new \RuntimeException('private details'));
        $job->failed(new \RuntimeException('private details'));
        $this->assertSame('failed', $export->fresh()->state);
        $this->assertStringNotContainsString('private', $export->fresh()->error);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_shipment_export_failed')->count());
    }

    public function test_deleted_account_keeps_export_snapshot_but_stops_job(): void
    {
        [$order, $account, , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        $account->delete();
        $this->runExport($export);
        $this->assertNull($export->fresh()->allegro_account_id);
        $this->assertSame('skipped', $export->fresh()->state);
        $this->assertSame('seller-1', $export->fresh()->seller_user_id);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        Http::assertNothingSent();
    }

    public function test_unknown_carrier_and_partial_existing_assignment_are_not_silently_overwritten(): void
    {
        [, , , $shipment] = $this->linkedOrder();
        $phase = 'carrier';
        Http::fake(function (Request $request) use (&$phase) {
            if (str_ends_with($request->url(), '/shipments')) {
                $partial = $this->remoteNumber('INPOST', 'NUMBER-2');
                $partial['lineItems'] = [];

                return Http::response(['shipments' => $phase === 'partial' ? [$partial] : []]);
            }

            return Http::response(str_ends_with($request->url(), '/carriers') ? ['carriers' => []] : Fixture::payload());
        });
        foreach (['carrier', 'partial'] as $i => $phase) {
            $shipment->update(['tracking_number' => 'NUMBER-'.($i + 1)]);
            $export = AllegroShipmentExport::query()->latest('id')->firstOrFail();
            $this->runExport($export);
            $this->assertSame('failed', $export->fresh()->state);
        }
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_recovery_requeues_only_known_stale_work_without_backfill_or_http(): void
    {
        [, , , $shipment] = $this->linkedOrder();
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $export = AllegroShipmentExport::query()->sole();
        Queue::fake();
        $export->update(['updated_at' => now()->subMinutes(16)]);
        app(AllegroShipmentExportService::class)->recover();
        app(AllegroShipmentExportService::class)->recover();
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
        $this->assertDatabaseCount('allegro_shipment_exports', 1);
        $this->assertSame($export->attempt_token, $export->fresh()->attempt_token);
        Http::assertNothingSent();
    }

    public function test_recovery_can_release_waiting_row_but_never_retries_permanent_failure(): void
    {
        [$order, , , $shipment] = $this->linkedOrder('status');
        $shipment->update(['tracking_number' => 'NUMBER-1']);
        $order->update(['status' => 'pending']);
        app(AllegroShipmentExportService::class)->recover();
        Queue::assertPushed(ExportAllegroShipmentJob::class, 1);
        $export = AllegroShipmentExport::query()->sole();
        (new ExportAllegroShipmentJob($export->id, $export->attempt_token))->failed(new \RuntimeException('error'));
        Queue::fake();
        $export->fresh()->update(['updated_at' => now()->subDay()]);
        app(AllegroShipmentExportService::class)->recover();
        Queue::assertNothingPushed();
        $this->assertSame('failed', $export->fresh()->state);
    }

    private function linkedOrder(string $mode = 'immediate', string $provider = CourierAccount::PROVIDER_INPOST_LOCKERS): array
    {
        OrderStatusSetting::syncDefaults();
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill([
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1',
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS,
            'shipment_sync_mode' => $mode, 'shipment_sync_identity' => 'production:seller-1', 'shipment_sync_revision' => 1,
            'shipment_sync_status_setting_id' => $mode === 'status' ? OrderStatusSetting::query()->where('status', 'pending')->value('id') : null,
        ])->save();
        $order = Order::query()->create([
            'source' => 'allegro', 'external_id' => Fixture::ORDER_ID, 'status' => 'new', 'currency' => 'PLN',
            'total_gross' => '100.03', 'paid_amount' => '100.03', 'payment_status' => 'paid', 'notes' => 'Numery seryjne',
        ]);
        $link = AllegroOrderLink::query()->create([
            'allegro_account_id' => $account->id, 'order_id' => $order->id, 'environment' => 'production', 'seller_user_id' => 'seller-1',
            'account_name' => 'multi-click', 'external_order_id' => Fixture::ORDER_ID, 'external_status' => 'READY_FOR_PROCESSING',
            'fulfillment_status' => 'NEW', 'source_hash' => hash('sha256', 'order'), 'imported_at' => now(), 'import_state' => 'complete',
        ]);
        $courier = CourierAccount::query()->create([
            'name' => 'Courier', 'provider' => $provider, 'environment' => 'production', 'is_active' => true,
            'allegro_account_id' => $provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING ? $account->id : null,
        ]);
        $shipment = Shipment::query()->create([
            'order_id' => $order->id, 'courier_account_id' => $courier->id, 'provider' => $provider,
            'service' => $provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING ? Shipment::SERVICE_ALLEGRO_DELIVERY : Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            'status' => 'confirmed', 'external_id' => 'courier-shipment', 'request_uuid' => (string) Str::uuid(),
        ]);

        return [$order, $account, $link, $shipment];
    }

    private function runExport(AllegroShipmentExport $export): void
    {
        (new ExportAllegroShipmentJob($export->id, $export->attempt_token))->handle(app(AllegroShipmentExportService::class));
    }

    private function dimensions(): array
    {
        return ['weight' => '1.000', 'length' => '20.00', 'width' => '15.00', 'height' => '10.00'];
    }

    private function remoteNumber(string $carrier, string $waybill): array
    {
        return ['id' => 'remote-shipment', 'carrierId' => $carrier, 'waybill' => $waybill,
            'lineItems' => [['id' => Fixture::payload()['lineItems'][0]['id']]]];
    }

    private function fakeApi(array $remote = [], int $postStatus = 201): void
    {
        Http::fake(function (Request $request) use ($remote, $postStatus) {
            if ($request->method() === 'POST') {
                return Http::response($this->remoteNumber($request['carrierId'], $request['waybill']), $postStatus);
            }
            if (str_ends_with($request->url(), '/shipments')) {
                return Http::response(['shipments' => $remote]);
            }

            return Http::response(str_ends_with($request->url(), '/carriers') ? ['carriers' => [['id' => 'INPOST'], ['id' => 'DPD']]] : Fixture::payload());
        });
    }
}
