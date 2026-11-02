<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Automation\Models\AutomationRule;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderLink;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderImportService;
use Modules\Integrations\VonHalsky\Services\VonHalskyOrderPreviewService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VonHalskyOrderImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        OrderStatusSetting::syncDefaults();
    }

    public function test_manual_import_uses_confirmed_quantity_and_emits_standard_import_events(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        AutomationRule::query()->create(['name' => 'Wszystkie źródła', 'trigger' => 'order.created', 'is_active' => true,
            'conditions' => []]);
        Event::fake([OrderCreated::class, OrderDataCompleted::class, OrderPaymentRegistered::class]);
        $this->post(route('integrations.von-halsky.import'), $this->importPayload($hash))
            ->assertRedirect(route('orders.show', 1))->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $this->assertSame('von_halsky', $order->source);
        $this->assertSame('PG76P11', $order->external_id);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('195.00', $order->paid_amount);
        $this->assertSame('195.00', $order->total_gross);
        $this->assertSame('BLIK', $order->payment_method);
        $this->assertSame('APM_TEST', $order->pickup_point_id);
        $this->assertSame('Kwiatowa', $order->shipping_street);
        $this->assertSame('PL', $order->billing_country_code);
        $this->assertSame('Budynek punktu', $order->pickup_point_address);
        $this->assertSame('2026-10-28 09:17:51', $order->purchased_at->utc()->format('Y-m-d H:i:s'));
        $item = $order->items()->sole();
        $this->assertSame(1, (int) $item->quantity);
        $this->assertSame('195.00', $item->unit_price_gross);
        $this->assertNull($item->vat_rate);
        $this->assertNull($item->product_id);
        $link = VonHalskyOrderLink::query()->sole();
        $this->assertSame('manual', $link->quantities_snapshot[0]['source']);
        $this->assertFalse($link->automations_suppressed);
        $this->assertSame('PAYMENT_TEST', $link->payment_evidence[0]['paymentId']);
        $this->assertStringNotContainsString('PAYMENT_TEST', DB::table('von_halsky_order_links')->value('payment_evidence'));
        $this->assertArrayNotHasKey('payment_evidence', $link->toArray());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('integration_api_logs', 0);
        Event::assertDispatchedTimes(OrderCreated::class, 1);
        Event::assertDispatchedTimes(OrderDataCompleted::class, 1);
        Event::assertNotDispatched(OrderPaymentRegistered::class);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r): bool => $r->method() === 'GET');
    }

    public function test_import_is_available_in_existing_order_views_and_comment_and_payment_are_not_email_messages(): void
    {
        $account = $this->account();
        $payload = $this->order();
        $payload['comment'] = '<script>buyer comment</script>';
        Http::fake(['*' => Http::response($payload)]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('multi-click (InPost Von Halsky)')
            ->assertSee('Numer transakcji:')->assertSee('PG76P11')->assertSee('PAYMENT_TEST')
            ->assertSee('Opłacenie potwierdzone przez Von Halsky')->assertSee('data-von-halsky-comment', false)
            ->assertSee('<script>buyer comment</script>')->assertDontSee('<script>buyer comment</script>', false);
        $this->get(route('orders.index', ['source' => 'von_halsky']))->assertOk()
            ->assertSee('multi-click (InPost Von Halsky)')->assertSee('Komentarz kupującego')->assertSee('source-von-halsky');
        $this->assertDatabaseCount('order_email_messages', 0);
    }

    public function test_preview_requires_manual_quantities_and_confirmation_is_not_assumed(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11')['import'];
        $this->assertTrue($preview['requires_quantities']);
        $this->assertFalse($preview['importable']);
        $this->assertNull($preview['items'][0]['quantity']);
        $this->expectException(VonHalskyApiException::class);
        $this->importer()->import($account, 'PG76P11', $preview['source_hash'], [1], false);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_never_creates_order(mixed $quantity): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $this->post(route('integrations.von-halsky.import'), [...$this->importPayload($hash), 'quantities' => [$quantity]])
            ->assertSessionHasErrors('quantities.0');
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], ['1.5'], [true], [1000000], ['1e0']];
    }

    public function test_wrong_total_missing_quantities_and_extra_lines_block_import(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        foreach ([[2], [], [1, 1]] as $quantities) {
            try {
                $this->importer()->import($account, 'PG76P11', $hash, $quantities, true);
                $this->fail('Import without matching quantities must fail.');
            } catch (VonHalskyApiException) {
                $this->assertDatabaseCount('orders', 0);
            }
        }
    }

    public function test_multiple_units_are_imported_only_when_confirmed_and_totals_match(): void
    {
        $account = $this->account();
        $payload = $this->order();
        $payload['finalPrice']['amount'] = '390.00';
        $payload['basePrice']['amount'] = '390.00';
        Http::fake(['*' => Http::response($payload)]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [2], true);
        $this->assertSame(2, (int) $order->items()->sole()->quantity);
        $this->assertSame('390.00', $order->items()->sole()->total_price_gross);
        $this->assertSame('390.00', $order->total_gross);
    }

    #[DataProvider('blockedOrders')]
    public function test_unsupported_orders_cannot_be_imported(string $field, mixed $value): void
    {
        $account = $this->account();
        $payload = $this->order();
        data_set($payload, $field, $value);
        Http::fake(['*' => Http::response($payload)]);
        $preview = app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11', [1])['import'];
        $this->assertFalse($preview['importable']);
        $this->assertNotEmpty($preview['blockers']);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function blockedOrders(): array
    {
        return [['status', 'CREATED'], ['status', 'CANCELED'], ['paymentDetails.payments', []],
            ['paymentDetails.payments.0.paymentId', null], ['paymentDetails.payments.0.paymentDate', null],
            ['customer.email', 'invalid'], ['customer.phoneNumber', null], ['delivery.deliveryPoint', null],
            ['customer.address.countryCode', 'DE'], ['finalPrice.currency', 'EUR'],
            ['orderLines.0.offer.finalPrice.currency', 'EUR'], ['delivery.deliveryType', 'UNKNOWN']];
    }

    public function test_duplicate_import_opens_existing_order_and_preserves_manual_changes(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $order->update(['notes' => 'Do not replace', 'paid_amount' => '10.00', 'payment_status' => 'unpaid']);
        $again = $this->importer()->import($account, 'PG76P11', $hash, [99], true);
        $this->assertSame($order->id, $again->id);
        $this->assertSame('Do not replace', $again->notes);
        $this->assertSame('10.00', $again->paid_amount);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('von_halsky_order_links', 1);
        Http::assertSentCount(2);
    }

    #[DataProvider('deletionModes')]
    public function test_deleted_or_trashed_order_is_not_reimported(bool $force): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $force ? $order->forceDelete() : $order->delete();
        try {
            $this->importer()->import($account, 'PG76P11', $hash, [1], true);
            $this->fail('Deleted orders must not be recreated.');
        } catch (VonHalskyApiException) {
            $this->assertSame($force ? 0 : 1, Order::withTrashed()->count());
            $this->assertDatabaseCount('von_halsky_order_links', 1);
            if ($force) {
                $this->assertNull(VonHalskyOrderLink::query()->sole()->order_id);
            }
        }
        Http::assertSentCount(2);
    }

    public static function deletionModes(): array
    {
        return [[false], [true]];
    }

    public function test_changed_remote_data_requires_a_new_preview(): void
    {
        $account = $this->account();
        Http::fakeSequence()->push($this->order())->push([...$this->order(), 'comment' => 'Changed']);
        $hash = $this->preview($account);
        $this->post(route('integrations.von-halsky.import'), $this->importPayload($hash))
            ->assertSessionHasErrors('von_halsky_import');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_absent_expired_or_wrong_session_proof_prevents_network_and_import(): void
    {
        $account = $this->account();
        $payload = $this->importPayload(str_repeat('a', 64));
        $this->post(route('integrations.von-halsky.import'), $payload)->assertSessionHasErrors('von_halsky_import');
        $this->withSession(['von_halsky_order_preview' => ['account_id' => $account->id, 'expires_at' => now()->subMinute()->timestamp]])
            ->post(route('integrations.von-halsky.import'), $payload)->assertSessionHasErrors('von_halsky_import');
        Http::assertNothingSent();
    }

    public function test_dynamic_status_and_optional_default_vat_preserve_zero_and_credentials(): void
    {
        $account = $this->account();
        $status = OrderStatusSetting::query()->create(['status' => 'custom-halsky', 'short_name' => 'Własny status', 'sort_order' => 50]);
        $this->post(route('integrations.von-halsky.import-settings'), ['expected_lock_version' => 0,
            'initial_status_setting_id' => $status->id, 'default_vat_rate' => '0'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertSame('TEST_SECRET', $account->client_secret);
        $this->assertSame('0.00', $account->default_vat_rate);
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $this->assertSame('custom-halsky', $order->status);
        $this->assertSame('0.00', $order->items()->sole()->vat_rate);
    }

    public function test_invalid_settings_and_stale_settings_are_rejected(): void
    {
        $this->account();
        $this->post(route('integrations.von-halsky.import-settings'), ['expected_lock_version' => 2, 'default_vat_rate' => '23'])
            ->assertSessionHasErrors('expected_lock_version');
        $this->post(route('integrations.von-halsky.import-settings'), ['expected_lock_version' => 0, 'default_vat_rate' => '101', 'initial_status_setting_id' => 999999])
            ->assertSessionHasErrors(['default_vat_rate', 'initial_status_setting_id']);
        Http::assertNothingSent();
    }

    public function test_configuration_change_during_remote_read_is_rejected_before_persisting(): void
    {
        $account = $this->account();
        Http::fake(function () use ($account) {
            app(VonHalskyAccountService::class)->updateImportSettings(['expected_lock_version' => $account->lock_version, 'default_vat_rate' => '23']);

            return Http::response($this->order());
        });
        try {
            $this->importer()->import($account, 'PG76P11', str_repeat('a', 64), [1], true);
            $this->fail('Stale account import must fail.');
        } catch (VonHalskyApiException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_numeric_json_money_is_imported_exactly_without_float_arithmetic(): void
    {
        $account = $this->account();
        $raw = json_encode($this->order(), JSON_THROW_ON_ERROR);
        $raw = str_replace('"amount":195', '"amount":195.99', $raw);
        Http::fake(['*' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $this->assertSame('195.99', $order->total_gross);
        $this->assertSame('195.99', $order->items()->sole()->unit_price_gross);
    }

    public function test_hidden_subcent_precision_is_not_rounded_into_an_importable_amount(): void
    {
        $account = $this->account();
        $raw = str_replace('"amount":195', '"amount":195.000000000000000001', json_encode($this->order(), JSON_THROW_ON_ERROR));
        Http::fake(['*' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);
        try {
            app(VonHalskyOrderPreviewService::class)->preview($account, 'PG76P11', [1]);
            $this->fail('Subcent precision must not be rounded away.');
        } catch (VonHalskyApiException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_api_quantity_cannot_be_overridden_by_confirmation(): void
    {
        $account = $this->account();
        $data = $this->order();
        $data['orderLines'][0]['quantity'] = 2;
        $data['finalPrice']['amount'] = '390.00';
        Http::fake(['*' => Http::response($data)]);
        $hash = $this->preview($account);
        try {
            $this->importer()->import($account, 'PG76P11', $hash, [1], true);
            $this->fail('API quantity cannot be overridden.');
        } catch (VonHalskyApiException) {
            $this->assertDatabaseCount('orders', 0);
        }
        $order = $this->importer()->import($account, 'PG76P11', $hash, [2], true);
        $this->assertSame('api', $order->vonHalskyOrderLink->quantities_snapshot[0]['source']);
    }

    public function test_key_order_alone_does_not_invalidate_source_confirmation(): void
    {
        $account = $this->account();
        Http::fakeSequence()->push($this->order())->push(array_reverse($this->order(), true));
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $this->assertSame('PG76P11', $order->external_id);
    }

    public function test_same_external_number_in_another_organization_is_not_a_duplicate(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $first = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $account->forceFill(['organization_id' => '497f6eca-6276-4993-bfeb-53cbbbba6f08'])->save();
        $data = $this->order();
        $data['organizationId'] = $account->organization_id;
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*' => Http::response($data)]);
        $hash = $this->preview($account);
        $second = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('von_halsky_order_links', 2);
    }

    public function test_transaction_failure_rolls_back_order_items_link_and_events(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        DB::statement("CREATE TRIGGER vh_test_fail BEFORE INSERT ON von_halsky_order_links BEGIN SELECT RAISE(ABORT, 'TEST_FAILURE'); END");
        try {
            $this->importer()->import($account, 'PG76P11', $hash, [1], true);
            $this->fail('Failure must roll back the whole import.');
        } catch (QueryException) {
            foreach (['orders', 'order_items', 'von_halsky_order_links', 'order_events'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_account_deletion_preserves_source_identity_and_historical_payment_evidence(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $order = $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $account->delete();
        $link = $order->vonHalskyOrderLink()->firstOrFail();
        $this->assertNull($link->von_halsky_account_id);
        $this->assertSame('multi-click', $link->account_name);
        $this->assertSame('PAYMENT_TEST', $link->payment_evidence[0]['paymentId']);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('multi-click (InPost Von Halsky)');
    }

    public function test_changed_import_settings_after_preview_require_new_confirmation_without_api_call(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        app(VonHalskyAccountService::class)->updateImportSettings(['expected_lock_version' => 0, 'default_vat_rate' => '23']);
        $this->post(route('integrations.von-halsky.import'), $this->importPayload($hash))->assertSessionHasErrors('von_halsky_import');
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public function test_valid_hash_still_cannot_bypass_a_settings_change_during_network(): void
    {
        $account = $this->account();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 2) {
                app(VonHalskyAccountService::class)->updateImportSettings(['expected_lock_version' => 0, 'default_vat_rate' => '23']);
            }

            return Http::response($this->order());
        });
        $hash = $this->preview($account);
        $this->post(route('integrations.von-halsky.import'), $this->importPayload($hash))->assertSessionHasErrors('von_halsky_import');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_migration_protects_import_history_from_rollback(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $hash = $this->preview($account);
        $this->importer()->import($account, 'PG76P11', $hash, [1], true);
        $migration = require database_path('migrations/2026_10_29_020000_add_von_halsky_manual_order_import.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function importer(): VonHalskyOrderImportService
    {
        return app(VonHalskyOrderImportService::class);
    }

    private function preview(VonHalskyAccount $account): string
    {
        return $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => $account->lock_version, 'order_identifier' => 'PG76P11'])
            ->assertOk()->viewData('orderPreview')['import']['source_hash'];
    }

    private function importPayload(string $hash): array
    {
        return ['order_identifier' => 'PG76P11', 'preview_hash' => $hash, 'quantities' => [1],
            'confirmed_quantities' => '1'];
    }

    private function account(): VonHalskyAccount
    {
        return VonHalskyAccount::query()->create(['name' => 'multi-click', 'environment' => 'production',
            'organization_id' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67', 'client_id' => 'TEST_CLIENT',
            'client_secret' => 'TEST_SECRET', 'access_token' => 'TEST_TOKEN', 'token_expires_at' => now()->addMinutes(5),
            'is_active' => true, 'last_test_status' => 'success']);
    }

    private function order(): array
    {
        return ['id' => 'PG76P11', 'organizationId' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67',
            'status' => 'ACCEPTED', 'createdAt' => '2026-10-28T09:17:51.841Z',
            'customer' => ['firstName' => 'Jan', 'lastName' => 'Testowy', 'email' => 'test@example.invalid', 'phoneNumber' => '+48500500500',
                'address' => ['street' => 'Kwiatowa', 'building' => '1', 'postCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL']],
            'invoice' => null, 'comment' => null,
            'delivery' => ['deliveryType' => 'APM', 'deliveryPoint' => 'APM_TEST', 'address' => ['street' => 'Budynek punktu'], 'price' => ['amount' => 0, 'currency' => 'PLN']],
            'orderLines' => [['offer' => ['offerId' => 'offer-test', 'product' => ['name' => 'Produkt testowy'],
                'price' => ['amount' => 195, 'currency' => 'PLN'], 'finalPrice' => ['amount' => 195, 'currency' => 'PLN']]]],
            'finalPrice' => ['amount' => 195, 'currency' => 'PLN'], 'basePrice' => ['amount' => 195, 'currency' => 'PLN'],
            'paymentDetails' => ['selectedPaymentType' => 'BLIK_CODE', 'payments' => [
                ['paymentId' => 'PAYMENT_TEST', 'paymentType' => 'BLIK_CODE', 'paymentDate' => '2026-10-28T09:18:30.017Z'],
            ]]];
    }
}
