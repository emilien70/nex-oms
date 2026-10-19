<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopPaymentsJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;
use Modules\Integrations\Prestashop\Services\PrestashopPaymentSyncDispatcher;
use Modules\Integrations\Prestashop\Services\PrestashopPaymentSyncService;
use Tests\TestCase;

class PrestashopPaymentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_payment_is_accounted_once_and_dispatches_automation(): void
    {
        Queue::fake();
        [$order, $account] = $this->linkedOrder();
        $this->fakePayments('40.00', [$this->payment(9101, '40.00', 'PS-TX-1')]);

        $result = app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->assertSame(1, $result['discovered']);
        $this->assertSame(1, $result['accounted']);
        $this->assertSame('40.00', $order->fresh()->paid_amount);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('prestashop_order_payments', [
            'external_payment_id' => 9101,
            'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
            'needs_review' => false,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'prestashop_payment_registered',
            'title' => 'Zarejestrowano płatność PrestaShop',
        ]);
        Queue::assertPushed(
            EvaluateAutomationEventJob::class,
            fn (EvaluateAutomationEventJob $job): bool => $job->eventName === 'payment.registered'
                && ($job->eventPayload['payment_source'] ?? null) === 'prestashop'
                && ($job->eventPayload['payment_state'] ?? null) === 'partial',
        );

        app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->assertSame('40.00', $order->fresh()->paid_amount);
        $this->assertDatabaseCount('prestashop_order_payments', 1);
        $this->assertSame(1, $order->events()->where('event_type', 'prestashop_payment_registered')->count());
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
    }

    public function test_dopayment_completes_order_without_recounting_imported_payment(): void
    {
        Queue::fake();
        [$order, $account, $link] = $this->linkedOrder([
            'paid_amount' => '40.00',
        ]);
        $link->payments()->create([
            'external_payment_id' => 9101,
            'transaction_id' => 'PS-TX-1',
            'amount' => '40.00',
            'currency' => 'PLN',
            'method' => 'Przelew',
            'paid_at' => now()->subHour(),
            'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
            'accounted_at' => now()->subHour(),
        ]);
        $this->fakePayments('100.00', [
            $this->payment(9101, '40.00', 'PS-TX-1'),
            $this->payment(9102, '60.00', 'PS-TX-2'),
        ]);

        $result = app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $order->refresh();
        $this->assertSame(1, $result['discovered']);
        $this->assertSame(1, $result['accounted']);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertDatabaseCount('prestashop_order_payments', 2);
    }

    public function test_matching_paynow_transaction_is_marked_as_mirrored_without_double_accounting(): void
    {
        Queue::fake();
        [$order, $account] = $this->linkedOrder([
            'paid_amount' => '40.00',
        ]);
        PayNowPayment::query()->create([
            'order_id' => $order->id,
            'amount' => '40.00',
            'currency' => 'PLN',
            'external_id' => 'order-'.$order->id.'-paynow',
            'provider_payment_id' => 'SHARED-TX-1',
            'idempotency_key' => 'payment-sync-test-'.$order->id,
            'status' => PayNowPaymentStatus::Confirmed,
            'buyer_email_snapshot' => 'buyer@example.test',
            'description' => 'Test',
            'confirmed_at' => now(),
            'accounted_at' => now(),
        ]);
        $this->fakePayments('40.00', [$this->payment(9101, '40.00', 'SHARED-TX-1')]);

        $result = app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->assertSame(1, $result['mirrored']);
        $this->assertSame('40.00', $order->fresh()->paid_amount);
        $this->assertDatabaseHas('prestashop_order_payments', [
            'external_payment_id' => 9101,
            'accounting_status' => PrestashopOrderPayment::STATUS_MIRRORED,
            'matched_payment_source' => 'paynow',
        ]);
        $this->assertSame(0, $order->events()->where('event_type', 'prestashop_payment_registered')->count());
        Queue::assertNotPushed(EvaluateAutomationEventJob::class);
    }

    public function test_possible_duplicate_without_shared_transaction_requires_review(): void
    {
        Queue::fake();
        [$order, $account] = $this->linkedOrder([
            'paid_amount' => '40.00',
        ]);
        $this->fakePayments('40.00', [$this->payment(9101, '40.00', 'OTHER-TX')]);

        $result = app(PrestashopPaymentSyncService::class)->sync($account->id, true);

        $this->assertSame(1, $result['review']);
        $this->assertSame('40.00', $order->fresh()->paid_amount);
        $this->assertDatabaseHas('prestashop_order_payments', [
            'external_payment_id' => 9101,
            'accounting_status' => PrestashopOrderPayment::STATUS_REVIEW,
            'needs_review' => true,
            'safe_error_code' => 'possible_duplicate_payment',
        ]);
        Queue::assertNotPushed(EvaluateAutomationEventJob::class);
    }

    public function test_settings_manual_sync_and_fixed_minute_dispatch_are_available(): void
    {
        Queue::fake();
        [, $account] = $this->linkedOrder();

        $this->put(route('integrations.prestashop.payment-sync.update'), [
            'payment_sync_enabled' => '1',
        ])->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'payments']));

        $account->refresh();
        $this->assertTrue($account->payment_sync_enabled);
        $this->assertNull($account->last_payment_sync_at);
        $this->assertTrue(app(PrestashopPaymentSyncDispatcher::class)->dispatchDue());
        Queue::assertPushed(SyncPrestashopPaymentsJob::class, 1);

        $account->forceFill(['last_payment_sync_at' => now()])->save();
        $this->assertFalse(app(PrestashopPaymentSyncDispatcher::class)->dispatchDue());
        Queue::assertPushed(SyncPrestashopPaymentsJob::class, 1);

        app(UniqueLock::class)->release(new SyncPrestashopPaymentsJob($account->id));

        $this->post(route('integrations.prestashop.payment-sync.run'))
            ->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'payments']))
            ->assertSessionHas('success', 'Synchronizacja płatności PrestaShop została zlecona.');
        Queue::assertPushed(
            SyncPrestashopPaymentsJob::class,
            fn (SyncPrestashopPaymentsJob $job): bool => $job->fullScan
                && $job->queue === 'integrations',
        );

        $this->get(route('integrations.prestashop.edit', ['tab' => 'payments']))
            ->assertOk()
            ->assertSeeText('Synchronizacja płatności')
            ->assertSeeText('Sprawdzanie odbywa się co 1 minutę')
            ->assertDontSee('status_sync_interval_minutes');
        $this->assertSame(
            'PrestaShop',
            app(AutomationCatalog::class)->conditionDefinitions()['payment_source']['options']['prestashop'],
        );
    }

    public function test_order_page_shows_prestashop_payment_details(): void
    {
        [$order, , $link] = $this->linkedOrder();
        $link->payments()->create([
            'external_payment_id' => 9101,
            'transaction_id' => 'PS-TX-1',
            'amount' => '40.00',
            'currency' => 'PLN',
            'method' => 'Przelew',
            'paid_at' => now(),
            'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
            'accounted_at' => now(),
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText('Zarejestrowano płatność PrestaShop')
            ->assertSeeText('Metoda: Przelew')
            ->assertSeeText('ID transakcji: PS-TX-1')
            ->assertSeeText('Zaksięgowano automatycznie');
    }

    /** @return array{Order, PrestashopAccount, PrestashopOrderLink} */
    private function linkedOrder(array $orderOverrides = []): array
    {
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
            'available_currencies' => [
                ['id' => 1, 'name' => 'Złoty', 'iso_code' => 'PLN'],
            ],
        ]);
        $order = Order::query()->create(array_merge([
            'source' => 'prestashop',
            'external_id' => 'PS-13581',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ], $orderOverrides));
        $link = $account->orderLinks()->create([
            'order_id' => $order->id,
            'external_order_id' => 13581,
            'external_reference' => 'PS-13581',
            'source_hash' => hash('sha256', 'prestashop-order-13581'),
            'imported_at' => now(),
        ]);

        return [$order, $account, $link];
    }

    /** @return array<string, int|string> */
    private function payment(int $id, string $amount, string $transactionId): array
    {
        return [
            'id' => $id,
            'order_reference' => 'PS-13581',
            'id_currency' => 1,
            'amount' => $amount,
            'payment_method' => 'Przelew',
            'transaction_id' => $transactionId,
            'date_add' => '2026-10-15 10:15:00',
        ];
    }

    /** @param array<int, array<string, int|string>> $payments */
    private function fakePayments(string $paidAmount, array $payments): void
    {
        Http::fake(function (Request $request) use ($paidAmount, $payments) {
            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/api/orders' => Http::response(['orders' => [[
                    'id' => 13581,
                    'total_paid_real' => $paidAmount,
                    'date_upd' => '2026-10-15 10:16:00',
                ]]]),
                '/api/order_payments' => Http::response(['order_payments' => $payments]),
                '/api/currencies/1' => Http::response(['currencies' => [[
                    'id' => 1,
                    'iso_code' => 'PLN',
                ]]]),
                default => Http::response([], 404),
            };
        });
    }
}
