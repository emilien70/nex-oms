<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnParcelEvent;
use App\Models\ReturnStatusSetting;
use App\Services\OrderReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroReturnImportService;
use Modules\Integrations\Allegro\Services\AllegroReturnNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroReturnFixture;
use Tests\TestCase;

class OrderReturnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_list_uses_order_list_styles_sidebar_and_real_controls(): void
    {
        $response = $this->get(route('returns.index'))->assertOk()->assertSee('Stwórz zwrot')->assertSee('Menu zwrotów')
            ->assertSee('orders-table')->assertSee('orders-context-sidebar')->assertSee('Filtrowanie zwrotów')
            ->assertSeeInOrder(['Akcje', 'Usuń zwroty', 'Zmień status'])
            ->assertSee('Tej operacji nie można cofnąć.')
            ->assertSee('formaction="'.route('returns.bulk-delete').'"', false)
            ->assertSee('Nowe')->assertSee('Odebrane')->assertSee('W trakcie')->assertSee('Zakończone')->assertSee('Odrzucone')
            ->assertSee('class="orders-context-add orders-context-add--return"', false)
            ->assertSee('class="bi bi-plus-circle orders-context-plus" aria-hidden="true"', false);
        $this->assertSame(2, substr_count($response->getContent(), 'class="nex-pagination-toolbar"'));
        $this->assertSame(4, substr_count($response->getContent(), 'data-return-bulk disabled'));
        $response->assertSeeInOrder([
            'id="return-list-toolbar"',
            'class="nex-pagination-toolbar"',
            'data-list-toolbar-dock role="group" aria-label="Dolny pasek listy" hidden inert>',
            'class="nex-pagination-toolbar"',
            'aria-label="Wszystkie zwroty"',
            'data-list-toolbar-spacer aria-hidden="true"',
        ], false);
        $this->get(route('returns.create'))->assertOk()->assertSee('Bez zamówienia')->assertSee('Z zamówienia');
        Http::assertNothingSent();
    }

    public function test_standalone_return_has_exact_money_and_no_fake_allegro_identity(): void
    {
        $data = $this->payload();
        $this->post(route('returns.store'), $data)->assertRedirect();
        $record = OrderReturn::query()->sole();
        $this->assertSame('0.09', $record->total_value);
        $this->assertSame('0.03', $record->items()->sole()->unit_price);
        $this->assertSame('manual', $record->source);
        $this->assertSame('manual', $record->creation_method);
        $this->assertSame('new', $record->localStatus->code);
        foreach (['allegro_account_id', 'external_return_id', 'external_order_id', 'environment', 'seller_user_id', 'status', 'source_hash', 'next_sync_at'] as $field) {
            $this->assertNull($record->$field);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame('manual_created', $record->events()->sole()->type);
        $this->get(route('returns.show', $record))->assertOk()->assertSee('Jan Klient')->assertSee('Ręcznie')->assertSee('Uwagi testowe')->assertSee('Brak danych')->assertDontSee('https://salescenter.allegro.com/orders/"', false);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('sources')]
    public function test_return_can_be_created_from_every_order_source(string $source): void
    {
        $order = $this->order($source);
        $before = $order->fresh()->getAttributes();
        $data = $this->forOrder($order, 2);
        $data['items'][0]['name'] = 'Tampered';
        $data['items'][0]['unit_price'] = '1.00';
        $this->post(route('returns.store'), $data)->assertRedirect();
        $record = OrderReturn::query()->sole();
        $this->assertSame($source, $record->source);
        $this->assertSame($order->id, $record->order_id);
        $this->assertSame('60.02', $record->total_value);
        $this->assertSame('Produkt historyczny', $record->items()->sole()->name);
        $this->assertSame('30.01', $record->items()->sole()->unit_price);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertNull($record->allegro_account_id);
        $this->get(route('orders.show', $order))->assertOk()->assertSee(route('returns.create', ['order_id' => $order->id]), false)->assertSee(route('returns.show', $record), false);
        $this->get(route('returns.show', $record))->assertOk();
        Http::assertNothingSent();
    }

    public static function sources(): array
    {
        return [['manual'], ['prestashop'], ['allegro'], ['other_shop']];
    }

    public function test_duplicate_submit_is_idempotent_but_key_reuse_with_other_data_is_rejected(): void
    {
        $data = $this->payload();
        $this->post(route('returns.store'), $data)->assertRedirect();
        $this->post(route('returns.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('order_returns', 1);
        $this->assertDatabaseCount('order_return_events', 1);
        $data['notes'] = 'Other intent';
        $this->post(route('returns.store'), $data)->assertSessionHasErrors('return');
        $this->assertDatabaseCount('order_returns', 1);
    }

    public function test_multiple_partial_returns_reserve_quantities_without_modifying_order(): void
    {
        $order = $this->order();
        $this->post(route('returns.store'), $this->forOrder($order, 2))->assertRedirect();
        $this->post(route('returns.store'), $this->forOrder($order, 2))->assertSessionHasErrors('return');
        $this->post(route('returns.store'), $this->forOrder($order, 1))->assertRedirect();
        $this->assertDatabaseCount('order_returns', 2);
        $this->assertSame(3, (int) $order->items()->sole()->quantity);
        $this->assertSame(0, $this->get(route('returns.create', ['order_id' => $order->id]))->assertOk()->viewData('remaining')->first());
    }

    public function test_rejected_return_releases_quantity_and_reopening_cannot_overclaim(): void
    {
        $order = $this->order();
        $this->post(route('returns.store'), $this->forOrder($order, 3));
        $record = OrderReturn::query()->sole();
        $rejected = ReturnStatusSetting::query()->where('code', 'rejected')->sole();
        $this->patch(route('returns.status', $record), ['local_status_id' => $rejected->id, 'expected_lock_version' => 0])->assertRedirect();
        $this->post(route('returns.store'), $this->forOrder($order, 3))->assertRedirect();
        $this->patch(route('returns.status', $record), ['local_status_id' => ReturnStatusSetting::initial()->id, 'expected_lock_version' => 1])->assertSessionHasErrors('return');
        $this->assertSame($rejected->id, $record->fresh()->local_status_id);
    }

    public function test_foreign_duplicate_and_empty_order_lines_are_rejected_atomically(): void
    {
        $order = $this->order();
        $other = $this->order();
        $data = $this->forOrder($order, 1);
        $data['items'][0]['order_item_id'] = $other->items()->sole()->id;
        $this->post(route('returns.store'), $data)->assertSessionHasErrors('return');
        $data = $this->forOrder($order, 1);
        $data['items'][] = $data['items'][0];
        $this->post(route('returns.store'), $data)->assertSessionHasErrors('return');
        $this->post(route('returns.store'), $this->forOrder($order, 0))->assertSessionHasErrors('return');
        $this->assertDatabaseCount('order_returns', 0);
        $this->assertDatabaseCount('order_return_items', 0);
    }

    public function test_order_changes_or_trash_after_opening_form_block_creation(): void
    {
        $order = $this->order();
        $data = $this->forOrder($order, 1);
        $order->items()->update(['unit_price_gross' => '99.00']);
        $this->post(route('returns.store'), $data)->assertSessionHasErrors('return');
        $data = $this->forOrder($order->fresh(), 1);
        $order->delete();
        $this->post(route('returns.store'), $data)->assertSessionHasErrors('return');
        $this->get(route('returns.create', ['order_id' => $order->id]))->assertNotFound();
        $this->assertDatabaseCount('order_returns', 0);
    }

    #[DataProvider('invalidStandalone')]
    public function test_invalid_standalone_data_never_creates_partial_return(string $path, mixed $value): void
    {
        $data = $this->payload();
        data_set($data, $path, $value);
        $this->post(route('returns.store'), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('order_returns', 0);
        $this->assertDatabaseCount('order_return_events', 0);
    }

    public static function invalidStandalone(): array
    {
        return [['buyer_name', ''], ['currency', 'XYZ'], ['request_key', 'bad'], ['items', []], ['items.0.name', ''],
            ['items.0.quantity', -1], ['items.0.quantity', 1.5], ['items.0.unit_price', '-1.00'], ['items.0.unit_price', '1.001'], ['address.country_code', 'XX']];
    }

    public function test_local_status_and_stars_have_version_checks_and_bulk_is_atomic(): void
    {
        $one = app(OrderReturnService::class)->create($this->payload());
        $two = app(OrderReturnService::class)->create($this->payload());
        $received = ReturnStatusSetting::query()->where('code', 'received')->sole();
        $this->patch(route('returns.star', $one), ['star_color' => 'blue', 'expected_lock_version' => 0])->assertRedirect();
        $this->patch(route('returns.star', $one), ['star_color' => 'red', 'expected_lock_version' => 0])->assertSessionHasErrors('return');
        $this->post(route('returns.bulk-status'), ['return_ids' => [$one->id, $two->id], 'versions' => [$one->id => 0, $two->id => 0], 'local_status_id' => $received->id])->assertSessionHasErrors('return');
        $this->assertSame('new', $two->fresh()->localStatus->code);
        $this->post(route('returns.bulk-status'), ['return_ids' => [$one->id, $two->id], 'versions' => [$one->id => 1, $two->id => 0], 'local_status_id' => $received->id])->assertRedirect();
        $this->assertSame('received', $one->fresh()->localStatus->code);
        $this->assertSame('received', $two->fresh()->localStatus->code);
        $this->assertSame(2, $one->events()->count());
        $this->assertSame(1, $this->get(route('returns.index', ['starred' => 1]))->assertOk()->viewData('returns')->total());
    }

    public function test_status_settings_are_independent_editable_and_used_status_is_protected(): void
    {
        $this->get(route('settings.return-statuses.index'))->assertOk()->assertSee('Statusy zwrotów');
        $orderStatuses = DB::table('order_status_settings')->get()->toJson();
        $this->post(route('settings.return-statuses.store'), ['name' => 'Kontrola', 'color' => '#129abc', 'sort_order' => 7])->assertRedirect();
        $status = ReturnStatusSetting::query()->where('name', 'Kontrola')->sole();
        $record = app(OrderReturnService::class)->create($this->payload());
        $this->patch(route('returns.status', $record), ['local_status_id' => $status->id, 'expected_lock_version' => 0])->assertRedirect();
        $this->patch(route('settings.return-statuses.update', $status), ['name' => 'Sprawdzenie', 'color' => '#333333', 'sort_order' => 8])->assertRedirect();
        $this->delete(route('settings.return-statuses.destroy', $status))->assertSessionHasErrors('status');
        $this->delete(route('settings.return-statuses.destroy', ReturnStatusSetting::initial()))->assertSessionHasErrors('status');
        $this->assertSame($orderStatuses, DB::table('order_status_settings')->get()->toJson());
        $this->get(route('returns.index', ['return_status' => $status->id]))->assertOk()->assertSee('Sprawdzenie');
        $this->assertSame('Kontrola', $record->events()->first()->status);
    }

    public function test_deletes_selected_manual_returns_permanently_and_keeps_order_and_outbound_shipment(): void
    {
        $service = app(OrderReturnService::class);
        $order = $this->order('allegro');
        $shipment = $order->shipments()->create([
            'provider' => 'inpost_courier', 'service' => 'test', 'tracking_number' => 'test-outbound',
            'request_uuid' => (string) Str::uuid(),
        ]);
        $one = $service->create($this->forOrder($order, 2));
        $two = $service->create($this->payload());
        $other = $service->create($this->payload());
        $one->parcels()->create(['position' => 1, 'waybill' => 'untracked', 'is_manual' => true]);
        $rejected = ReturnStatusSetting::query()->where('code', 'rejected')->sole();
        $service->changeStatuses([$two->id => $two->lock_version], $rejected->id);
        $orderBefore = $order->fresh()->getAttributes();
        $itemBefore = $order->items()->sole()->getAttributes();
        $shipmentBefore = $shipment->fresh()->getAttributes();
        $filterUrl = route('returns.index', ['q' => 'Jan Klient', 'per_page' => 50]);
        Queue::fake();

        $this->from($filterUrl)->post(route('returns.bulk-delete'), $this->deletion($one, $two->fresh()))
            ->assertRedirect($filterUrl)->assertSessionHasNoErrors()->assertSessionHas('success');

        foreach ([$one, $two] as $deleted) {
            $this->assertModelMissing($deleted);
            foreach (['order_return_items', 'order_return_parcels', 'order_return_events'] as $table) {
                $this->assertDatabaseMissing($table, ['order_return_id' => $deleted->id]);
            }
            $this->get(route('returns.show', $deleted))->assertNotFound();
        }
        $this->assertModelExists($other);
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $order->items()->sole()->getAttributes());
        $this->assertSame($shipmentBefore, $shipment->fresh()->getAttributes());
        $this->assertSame(0, $service->claimedQuantity($order->items()->sole()->id));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_confirmation_versions_and_distinct_selection_are_required_before_any_delete(): void
    {
        $record = app(OrderReturnService::class)->create($this->payload());
        $route = route('returns.bulk-delete');
        $data = $this->deletion($record);
        $this->get($route)->assertNotFound();
        $this->postJson($route, array_diff_key($data, ['confirm_delete' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_delete');
        $this->postJson($route, [...$data, 'versions' => [999999 => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors('return');
        $this->postJson($route, [...$data, 'return_ids' => [$record->id, (string) $record->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('return_ids.0');
        $this->postJson($route, [...$data, 'versions' => [$record->id => 99]])
            ->assertUnprocessable()->assertJsonPath('errors.return.0', "Zwrot #{$record->id} zmienił się w innym oknie. Odśwież listę.");
        $this->postJson($route, [...$data, 'return_ids' => [$record->id, 999999], 'versions' => $data['versions'] + [999999 => 0]])
            ->assertUnprocessable()->assertJsonPath('errors.return.0', 'Zwrot #999999 już nie istnieje. Odśwież listę.');
        $this->assertModelExists($record);
    }

    public function test_imported_return_blocks_entire_batch_and_keeps_selection_after_redirect(): void
    {
        $manual = app(OrderReturnService::class)->create($this->payload());
        $account = AllegroAccount::query()->create(['account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production', 'allegro_user_id' => 'seller']);
        $imported = app(AllegroReturnImportService::class)->store($account, app(AllegroReturnNormalizer::class)->normalize(AllegroReturnFixture::payload()));
        $url = route('returns.index');
        $data = $this->deletion($manual, $imported->fresh());

        $this->from($url)->post(route('returns.bulk-delete'), $data)->assertRedirect($url)
            ->assertSessionHasErrors(['return' => "Zwrot #{$imported->id} został zaimportowany. Usuwanie zwrotów z integracji nie jest dostępne."])
            ->assertSessionHasInput('return_ids', $data['return_ids']);
        $this->get($url)->assertOk()->assertSee('Zwrot #'.$imported->id.' został zaimportowany');
        $this->assertModelExists($manual);
        $this->assertModelExists($imported);
        Http::assertNothingSent();
    }

    public function test_history_of_processing_tracking_and_automation_blocks_delete(): void
    {
        $service = app(OrderReturnService::class);
        $handled = $service->create($this->payload());
        $received = ReturnStatusSetting::query()->where('code', 'received')->sole();
        $rejected = ReturnStatusSetting::query()->where('code', 'rejected')->sole();
        $service->changeStatuses([$handled->id => 0], $received->id);
        $this->postJson(route('returns.bulk-delete'), $this->deletion($handled->fresh()))
            ->assertUnprocessable()->assertJsonPath('errors.return.0', "Zwrot #{$handled->id} nie jest nowy ani odrzucony.");
        $service->changeStatuses([$handled->id => 1], $rejected->id);
        $tracked = $service->create($this->payload());
        OrderReturnParcelEvent::query()->create([
            'order_return_id' => $tracked->id, 'event_id' => (string) Str::uuid(),
            'tracking_identity' => str_repeat('a', 64), 'event_key' => str_repeat('b', 64),
            'provider_status' => 'delivered', 'tracking_status' => 'delivered', 'occurred_at' => now(),
        ]);
        $order = $this->order();
        $automated = $service->create($this->forOrder($order, 1));
        AutomationRun::query()->create([
            'order_id' => $order->id, 'order_return_id' => $automated->id, 'event_id' => (string) Str::uuid(),
            'event_name' => 'return.parcel_status_changed', 'chain_id' => (string) Str::uuid(),
            'status' => AutomationRun::STATUS_RUNNING, 'event_payload' => [], 'rule_snapshot' => [],
        ]);

        $this->postJson(route('returns.bulk-delete'), $this->deletion($handled->fresh(), $tracked, $automated))
            ->assertUnprocessable()
            ->assertJsonPath('errors.return', [
                "Zwrot #{$handled->id} ma wcześniejszą historię obsługi.",
                "Zwrot #{$tracked->id} ma historię śledzenia przesyłki zwrotnej.",
                "Zwrot #{$automated->id} ma powiązane wykonanie automatyzacji.",
            ]);
        $this->assertDatabaseCount('order_returns', 3);
        Http::assertNothingSent();
    }

    public function test_import_does_not_overwrite_local_workflow_and_never_adopts_manual_return(): void
    {
        $order = $this->order('allegro');
        $manual = app(OrderReturnService::class)->create($this->forOrder($order, 1));
        $account = AllegroAccount::query()->create(['account_key' => 'default', 'name' => 'multi-click', 'environment' => 'production', 'allegro_user_id' => 'seller']);
        $raw = AllegroReturnFixture::payload();
        $import = fn () => app(AllegroReturnImportService::class)->store($account, app(AllegroReturnNormalizer::class)->normalize($raw));
        $imported = $import();
        $received = ReturnStatusSetting::query()->where('code', 'received')->sole();
        app(OrderReturnService::class)->changeStatuses([$imported->id => 0], $received->id);
        $raw['status'] = 'FINISHED';
        $imported = app(AllegroReturnImportService::class)->store($account, app(AllegroReturnNormalizer::class)->normalize($raw));
        $this->assertSame($received->id, $imported->local_status_id);
        $this->assertSame('FINISHED', $imported->status);
        $this->assertNotSame($manual->id, $imported->id);
        $this->assertDatabaseCount('order_returns', 2);
        $this->assertNull($manual->fresh()->external_return_id);
    }

    public function test_history_survives_order_item_and_product_removal(): void
    {
        $order = $this->order();
        $this->post(route('returns.store'), $this->forOrder($order, 1));
        $record = OrderReturn::query()->sole();
        $orderId = $order->id;
        $order->items()->delete();
        $order->forceDelete();
        $record->refresh();
        $this->assertNull($record->order_id);
        $this->assertSame($orderId, $record->order_snapshot['order_id']);
        $this->assertSame('Produkt historyczny', $record->items()->sole()->name);
        $this->assertNull($record->items()->sole()->order_item_id);
        $this->get(route('returns.show', $record))->assertOk()->assertSee('Jan Klient')->assertSee('Testowa 12');
    }

    public function test_search_and_html_content_are_safe(): void
    {
        $data = $this->payload();
        $data['buyer_name'] = '<script>bad()</script>';
        $data['notes'] = '<b>uwagi</b>';
        $record = app(OrderReturnService::class)->create($data);
        $this->get(route('returns.index'))->assertOk()->assertSee($data['buyer_name'])->assertDontSee($data['buyer_name'], false);
        $this->get(route('returns.show', $record))->assertOk()->assertSee($data['notes'])->assertDontSee($data['notes'], false);
        $this->assertSame(0, $this->get(route('returns.index', ['q' => '%']))->assertOk()->viewData('returns')->total());
        Http::assertNothingSent();
    }

    public function test_order_lookup_supports_ids_and_literal_search_without_trashed_orders(): void
    {
        $order = $this->order('prestashop');
        $trashed = $this->order();
        $trashed->delete();
        $result = $this->get(route('returns.create', ['q' => (string) $order->id]))->assertOk();
        $this->assertTrue($result->viewData('candidates')->contains('id', $order->id));
        $this->assertFalse($result->viewData('candidates')->contains('id', $trashed->id));
        $this->assertCount(0, $this->get(route('returns.create', ['q' => '%']))->assertOk()->viewData('candidates'));
    }

    public function test_return_search_uses_portable_literal_matching_in_both_views(): void
    {
        $needle = 'A_%!\B';
        $decoy = 'AXYZ!\B';
        $record = app(OrderReturnService::class)->create(['buyer_name' => 'Kupujacy '.$needle] + $this->payload());
        app(OrderReturnService::class)->create(['buyer_name' => 'Kupujacy '.$decoy] + $this->payload());
        $order = $this->order();
        $order->update(['customer_login' => 'login'.$needle]);
        $this->order()->update(['customer_login' => 'login'.$decoy]);
        $record->parcels()->create(['position' => 0, 'transporting_waybill' => 'TRACK-'.$needle]);

        DB::enableQueryLog();
        try {
            $returns = $this->get(route('returns.index', ['q' => $needle]))->assertOk()->viewData('returns');
            $this->assertSame([$record->id], $returns->pluck('id')->all());
            $byWaybill = $this->get(route('returns.index', ['q' => 'TRACK-'.$needle]))->assertOk()->viewData('returns');
            $this->assertSame([$record->id], $byWaybill->pluck('id')->all());
            $candidates = $this->get(route('returns.create', ['q' => $needle]))->assertOk()->viewData('candidates');
            $this->assertSame([$order->id], $candidates->pluck('id')->all());
            $searchQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], ' LIKE ?'));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertNotEmpty($searchQueries);
        foreach ($searchQueries as $query) {
            $this->assertSame(substr_count($query['query'], ' LIKE ?'), substr_count($query['query'], " LIKE ? ESCAPE '!'"));
        }
    }

    public function test_manual_form_recovers_from_empty_or_non_contiguous_item_indexes(): void
    {
        $this->withSession(['_old_input' => ['items' => []]])->get(route('returns.create'))->assertOk();
        $this->withSession(['_old_input' => ['items' => [0 => ['quantity' => 1], 4 => ['quantity' => 2]]]])
            ->get(route('returns.create'))->assertOk()->assertSee('let next = 5;', false);
    }

    public function test_legacy_import_defaults_preserve_allegro_identity_and_local_initial_status(): void
    {
        $record = OrderReturn::query()->create([
            'environment' => 'production', 'seller_user_id' => 'legacy-seller',
            'external_return_id' => (string) Str::uuid(), 'external_order_id' => (string) Str::uuid(),
            'account_name' => 'Legacy', 'currency' => 'PLN', 'total_value' => '10.00',
            'submitted_at' => now(), 'status' => 'CREATED', 'source_hash' => str_repeat('a', 64),
            'status_observed_at' => now(), 'synced_at' => now(), 'next_sync_at' => now(),
        ])->fresh();
        $this->assertSame('allegro', $record->source);
        $this->assertSame('allegro_import', $record->creation_method);
        $this->assertSame('new', $record->localStatus->code);
        $this->get(route('returns.index'))->assertOk()->assertDontSee('@elseif', false);
    }

    public function test_unused_status_can_be_removed_and_invalid_color_is_rejected(): void
    {
        $this->post(route('settings.return-statuses.store'), ['name' => 'Błędny', 'color' => 'red', 'sort_order' => 0])
            ->assertSessionHasErrors('color');
        $this->post(route('settings.return-statuses.store'), ['name' => 'Tymczasowy', 'color' => '#123456', 'sort_order' => 10])
            ->assertRedirect();
        $status = ReturnStatusSetting::query()->where('name', 'Tymczasowy')->sole();
        $this->delete(route('settings.return-statuses.destroy', $status))->assertRedirect();
        $this->assertDatabaseMissing('return_status_settings', ['id' => $status->id]);
        $this->assertDatabaseCount('return_status_settings', 5);
    }

    private function payload(): array
    {
        return ['request_key' => (string) Str::uuid(), 'buyer_name' => 'Jan Klient', 'currency' => 'PLN', 'notes' => 'Uwagi testowe',
            'address' => ['street' => 'Testowa 12', 'city' => 'Krakow', 'country_code' => 'PL'],
            'items' => [['name' => 'Towar ręczny', 'quantity' => 3, 'unit_price' => '0.03', 'reason' => 'Pomyłka', 'comment' => 'Komentarz']]];
    }

    private function deletion(OrderReturn ...$returns): array
    {
        return [
            'confirm_delete' => 1,
            'return_ids' => array_map(fn ($return) => $return->id, $returns),
            'versions' => collect($returns)->mapWithKeys(fn (OrderReturn $return) => [$return->id => $return->fresh()->lock_version])->all(),
        ];
    }

    private function order(string $source = 'manual'): Order
    {
        $order = Order::query()->create(['source' => $source, 'external_id' => 'ORDER-REF', 'status' => 'new', 'currency' => 'PLN',
            'total_gross' => '90.03', 'paid_amount' => '90.03', 'shipping_name' => 'Jan Klient', 'shipping_street' => 'Testowa 12', 'shipping_country_code' => 'PL']);
        $order->items()->create(['product_name' => 'Produkt historyczny', 'quantity' => 3, 'unit_price_gross' => '30.01', 'total_price_gross' => '90.03', 'currency' => 'PLN']);

        return $order->fresh();
    }

    private function forOrder(Order $order, int $quantity): array
    {
        $response = $this->get(route('returns.create', ['order_id' => $order->id]))->assertOk();

        return ['request_key' => (string) Str::uuid(), 'order_id' => $order->id, 'order_fingerprint' => $response->viewData('orderFingerprint'),
            'items' => [['order_item_id' => $order->items()->sole()->id, 'quantity' => $quantity]]];
    }
}
