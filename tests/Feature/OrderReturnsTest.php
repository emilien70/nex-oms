<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use App\Services\OrderReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        $this->get(route('returns.index'))->assertOk()->assertSee('Stwórz zwrot')->assertSee('Menu zwrotów')
            ->assertSee('orders-table')->assertSee('orders-context-sidebar')->assertSee('Filtrowanie zwrotów')
            ->assertSee('Nowe')->assertSee('Odebrane')->assertSee('W trakcie')->assertSee('Zakończone')->assertSee('Odrzucone')
            ->assertSee('class="orders-context-add orders-context-add--return"', false)
            ->assertSee('class="bi bi-plus-circle orders-context-plus" aria-hidden="true"', false);
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
