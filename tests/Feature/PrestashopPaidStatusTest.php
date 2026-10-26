<?php

namespace Tests\Feature;

use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Services\PrestashopPaymentSyncService;
use Tests\TestCase;

class PrestashopPaidStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_paid_flag_accounts_order_without_inventing_payment_and_only_once(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $this->fakePayments('0.00', []);

        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->assertSame('100.00', $order->fresh()->paid_amount);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('100.00', $link->fresh()->status_paid_amount);
        $this->assertTrue($link->fresh()->external_payment_paid);
        $this->assertDatabaseCount('prestashop_order_payments', 0);
        $this->assertSame(1, $order->events()->where('event_type', 'prestashop_status_payment_registered')->count());
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
        Queue::assertPushed(EvaluateAutomationEventJob::class, fn ($job) => $job->eventName === 'payment.registered'
            && $job->eventPayload['payment_source'] === 'prestashop'
            && $job->eventPayload['provider_payment_id'] === null
            && $job->eventPayload['amount'] === '100.00');

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSeeText('Opłacenie na podstawie statusu PrestaShop')
            ->assertDontSee('data-payment-id-warning data-bs-toggle', false)
            ->assertDontSeeText('Brak zarejestrowanych płatności.');
    }

    public function test_late_native_payments_confirm_status_without_double_credit_or_automation(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $this->fakePayments('0.00', []);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->fakePayments('40.00', [$this->payment(9001, '40.00', 'TX-1')]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->fakePayments('100.00', [
            $this->payment(9001, '40.00', 'TX-1'),
            $this->payment(9002, '60.00', 'TX-2'),
        ]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->assertSame('100.00', $order->fresh()->paid_amount);
        $this->assertSame(2, $link->payments()->where('accounting_status', 'mirrored')->where('matched_payment_source', 'prestashop_status')->count());
        $this->assertSame(0, $link->payments()->where('needs_review', true)->count());
        $this->assertSame(0, $order->events()->where('event_type', 'prestashop_payment_registered')->count());
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
    }

    public function test_partial_native_payment_and_paid_flag_register_only_one_full_payment_event(): void
    {
        [$order, $account] = $this->linkedOrder();
        $this->fakePayments('40.00', [$this->payment(9001, '40.00', null)]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
        $this->assertDatabaseHas('prestashop_order_payments', ['external_payment_id' => 9001, 'accounting_status' => 'mirrored']);
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
    }

    public function test_unpaid_flag_does_not_assume_payment_and_later_status_does_not_zero_it(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $this->fakePayments('0.00', [], 1);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->assertSame('0.00', $order->fresh()->paid_amount);
        $this->assertFalse($link->fresh()->external_payment_paid);
        Queue::assertNotPushed(EvaluateAutomationEventJob::class);

        $this->fakePayments('0.00', [], 2);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->fakePayments('0.00', [], 1);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertFalse($link->fresh()->external_payment_paid);
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
    }

    public function test_existing_orders_outside_date_cursor_are_checked_in_bounded_batches(): void
    {
        [$order, $account] = $this->linkedOrder();
        $account->forceFill(['last_payment_sync_at' => now()])->save();
        $this->fakePayments('0.00', [], emptyChanges: true);
        app(PrestashopPaymentSyncService::class)->sync($account->id);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
        Http::assertSent(fn (Request $request) => isset($request->data()['filter[id]']));
    }

    public function test_warning_setting_is_optional_defaults_to_no_and_is_strictly_validated(): void
    {
        [, $account] = $this->linkedOrder();
        $this->assertFalse($account->fresh()->warn_missing_transaction_id);
        $this->put(route('integrations.prestashop.payment-sync.update'), [
            'payment_sync_enabled' => '0', 'warn_missing_transaction_id' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($account->fresh()->warn_missing_transaction_id);
        $this->assertFalse($account->fresh()->payment_sync_enabled);
        $this->put(route('integrations.prestashop.payment-sync.update'), ['payment_sync_enabled' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($account->fresh()->warn_missing_transaction_id);
        $this->put(route('integrations.prestashop.payment-sync.update'), [
            'payment_sync_enabled' => '0', 'warn_missing_transaction_id' => 'invalid',
        ])->assertSessionHasErrors('warn_missing_transaction_id');
        $this->get(route('integrations.prestashop.edit', ['tab' => 'payments']))->assertOk()
            ->assertSee('name="warn_missing_transaction_id"', false)
            ->assertSeeText('Wyświetlaj ostrzeżenie w przypadku braku identyfikatora transakcji:');
        Http::assertNothingSent();
    }

    public function test_warning_is_shown_for_missing_transaction_id_in_full_page_and_ajax_only_when_enabled(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $link->forceFill(['external_payment_paid' => true])->save();
        $account->forceFill(['warn_missing_transaction_id' => true])->save();
        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('data-payment-id-warning data-bs-toggle', false)
            ->assertSee('title="Brak ID płatności"', false);
        $this->getJson(route('orders.state', $order).'?fragments=order-info')->assertOk()
            ->assertJsonPath('fragments.order-info', fn ($html) => str_contains($html, 'data-payment-id-warning'));
        $this->getJson(route('orders.state', $order).'?payment_id_warning=0')->assertOk()
            ->assertJsonPath('payment_id_warning', true)
            ->assertJsonPath('fragments.order-info', fn ($html) => str_contains($html, 'data-payment-id-warning'));
        $account->forceFill(['warn_missing_transaction_id' => false])->save();
        $this->getJson(route('orders.state', $order).'?payment_id_warning=1')->assertOk()
            ->assertJsonPath('payment_id_warning', false)
            ->assertJsonPath('fragments.order-info', fn ($html) => ! str_contains($html, 'data-payment-id-warning'));
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('data-payment-id-warning data-bs-toggle', false);
        Http::assertNothingSent();
    }

    public function test_gateway_id_can_be_enriched_without_accounting_again_and_warning_disappears(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $account->forceFill(['warn_missing_transaction_id' => true])->save();
        $this->fakePayments('100.00', [$this->payment(9001, '100.00', null)]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('data-payment-id-warning data-bs-toggle', false);
        $this->fakePayments('100.00', [$this->payment(9001, '100.00', 'PAYNOW-REAL-ID')]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->assertSame('PAYNOW-REAL-ID', $link->payments()->sole()->transaction_id);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('data-payment-id-warning data-bs-toggle', false);
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
    }

    public function test_missing_one_of_multiple_ids_warns_but_unpaid_state_and_other_sources_do_not(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $account->forceFill(['warn_missing_transaction_id' => true])->save();
        $this->fakePayments('100.00', [$this->payment(9001, '40.00', 'TX'), $this->payment(9002, '60.00', null)]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('data-payment-id-warning data-bs-toggle', false);
        $link->forceFill(['external_payment_paid' => false])->save();
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('data-payment-id-warning data-bs-toggle', false);
        $link->forceFill(['external_payment_paid' => true])->save();
        $order->forceFill(['source' => 'manual'])->save();
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('data-payment-id-warning data-bs-toggle', false);
    }

    public function test_amount_mismatch_does_not_change_financial_state_or_create_payment_events(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $this->fakePayments('0.00', [], remoteTotal: '101.00');
        try {
            app(PrestashopPaymentSyncService::class)->sync($account->id, true);
            $this->fail('Expected safe failure');
        } catch (PrestashopImportException) {
            $this->assertSame('0.00', $order->fresh()->paid_amount);
            $this->assertNull($link->fresh()->status_paid_at);
            $this->assertDatabaseCount('order_events', 0);
            Queue::assertNotPushed(EvaluateAutomationEventJob::class);
        }
    }

    public function test_declared_payment_event_does_not_collide_with_native_record_identity(): void
    {
        [$order] = $this->linkedOrder();
        $args = [$order, 1, 'prestashop', null, '100.00', 'PLN', '0.00', '100.00', 'paid', now()->toIso8601String()];
        $native = new OrderPaymentRegistered(...$args);
        $declared = new OrderPaymentRegistered(...[...$args, 'prestashop-paid-status:1']);
        $this->assertNotSame($native->eventId, $declared->eventId);
        $this->assertSame($native->eventId, (new OrderPaymentRegistered(...$args))->eventId);
    }

    public function test_migration_refuses_to_remove_status_payment_history(): void
    {
        [, $account] = $this->linkedOrder();
        $this->fakePayments('0.00', []);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $migration = require database_path('migrations/2026_10_26_010000_add_prestashop_paid_status_tracking.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_overpayment_does_not_get_hidden_by_paid_flag(): void
    {
        [$order, $account] = $this->linkedOrder();
        $this->fakePayments('120.00', [$this->payment(9001, '120.00', 'TX')]);
        try {
            app(PrestashopPaymentSyncService::class)->sync($account->id, true);
            $this->fail('Expected safe failure');
        } catch (PrestashopImportException) {
            $this->assertSame('0.00', $order->fresh()->paid_amount);
            $this->assertDatabaseCount('prestashop_order_payments', 0);
            $this->assertDatabaseCount('order_events', 0);
            Queue::assertNotPushed(EvaluateAutomationEventJob::class);
        }
    }

    public function test_nonempty_gateway_id_cannot_be_replaced_and_changed_amount_is_rejected(): void
    {
        [$order, $account, $link] = $this->linkedOrder();
        $this->fakePayments('100.00', [$this->payment(9001, '100.00', 'ORIGINAL')]);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
        $this->fakePayments('100.00', [$this->payment(9001, '100.00', 'OTHER')]);
        try {
            app(PrestashopPaymentSyncService::class)->sync($account->id, true);
            $this->fail('Expected safe failure');
        } catch (PrestashopImportException) {
            $this->assertSame('ORIGINAL', $link->payments()->sole()->transaction_id);
            $this->assertSame('100.00', $order->fresh()->paid_amount);
        }
        $this->fakePayments('90.00', [$this->payment(9001, '90.00', 'ORIGINAL')]);
        $this->expectException(PrestashopImportException::class);
        app(PrestashopPaymentSyncService::class)->sync($account->id, true);
    }

    public function test_deleted_orders_are_not_checked_by_initial_status_backfill(): void
    {
        [$order, $account] = $this->linkedOrder();
        $order->delete();
        $account->forceFill(['last_payment_sync_at' => now()])->save();
        $this->fakePayments('0.00', [], emptyChanges: true);
        app(PrestashopPaymentSyncService::class)->sync($account->id);
        Http::assertNotSent(fn (Request $request) => isset($request->data()['filter[id]']));
        $this->assertSame('0.00', $order->fresh()->paid_amount);
    }

    private function linkedOrder(): array
    {
        $account = PrestashopAccount::query()->create([
            'account_key' => 'default', 'name' => 'Shop', 'base_url' => 'https://shop.example.test',
            'api_key' => 'test-key', 'is_active' => true,
            'available_currencies' => [['id' => 1, 'iso_code' => 'PLN']],
        ]);
        $account->orderStates()->createMany([
            ['external_id' => 1, 'name' => 'Awaiting payment', 'paid' => false, 'refreshed_at' => now()],
            ['external_id' => 2, 'name' => 'Custom paid state', 'paid' => true, 'refreshed_at' => now()],
        ]);
        $order = Order::query()->create([
            'source' => 'prestashop', 'external_id' => 'REF-13581', 'status' => 'new',
            'currency' => 'PLN', 'total_gross' => '100.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid',
        ]);
        $link = $account->orderLinks()->create([
            'order_id' => $order->id, 'external_order_id' => 13581, 'external_reference' => 'REF-13581',
            'external_order_state_id' => 2, 'source_hash' => hash('sha256', 'test'), 'imported_at' => now(),
        ]);

        return [$order, $account, $link];
    }

    private function payment(int $id, string $amount, ?string $transactionId): array
    {
        return ['id' => $id, 'order_reference' => 'REF-13581', 'amount' => $amount,
            'id_currency' => 1, 'transaction_id' => $transactionId, 'payment_method' => 'PayNow', 'date_add' => '2026-10-25 22:18:57'];
    }

    private function fakePayments(string $paid, array $payments, int $state = 2, string $remoteTotal = '100.00', bool $emptyChanges = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($paid, $payments, $state, $remoteTotal, $emptyChanges) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/orders' => Http::response(['orders' => $emptyChanges && isset($request->data()['filter[date_upd]']) ? [] : [[
                    'id' => 13581, 'total_paid_real' => $paid, 'total_paid_tax_incl' => $remoteTotal,
                    'id_currency' => 1, 'current_state' => $state, 'date_upd' => '2026-10-25 22:18:57',
                ]]]),
                '/api/order_payments' => Http::response(['order_payments' => $payments]),
                '/api/currencies/1' => Http::response(['currencies' => [['id' => 1, 'iso_code' => 'PLN']]]),
                default => Http::response([], 404),
            };
        });
    }
}
