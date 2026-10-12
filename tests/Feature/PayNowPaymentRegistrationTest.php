<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowReconciliationService;
use Tests\TestCase;

class PayNowPaymentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_confirmation_is_accounted_exactly_once(): void
    {
        $account = $this->account();
        $order = $this->order([
            'total_gross' => '125.50',
            'paid_amount' => '25.50',
        ]);
        $payment = $this->payment($account, $order, [
            'amount' => '100.00',
        ]);
        $body = $this->notification($payment, 'CONFIRMED', '2026-10-12T10:15:30+02:00');

        $this->postNotification($body, $account)->assertStatus(202)->assertContent('');
        $this->postNotification($body, $account)->assertStatus(202)->assertContent('');

        $order->refresh();
        $payment->refresh();

        $this->assertSame('125.50', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(PayNowPaymentStatus::Confirmed, $payment->status);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertNotNull($payment->accounted_at);
        $this->assertFalse($payment->needs_review);
        $this->assertSame(1, $payment->events()->where('source', 'webhook')->count());
        $this->assertSame(1, $payment->events()->where('source', 'accounting')->count());
        $this->assertSame(1, $order->events()->where('event_type', 'paynow_payment_registered')->count());
    }

    public function test_invalid_signature_is_rejected_without_recording_an_event(): void
    {
        $account = $this->account();
        $payment = $this->payment($account, $this->order());
        $body = $this->notification($payment, 'CONFIRMED', '2026-10-12T10:15:30+02:00');

        $this->postNotification($body, $account, 'invalid-signature')
            ->assertStatus(401)
            ->assertContent('');

        $this->assertSame(PayNowPaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('paynow_payment_events', 0);
    }

    public function test_partial_payment_updates_the_amount_without_marking_order_as_paid(): void
    {
        $account = $this->account();
        $order = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '10.00',
        ]);
        $payment = $this->payment($account, $order, ['amount' => '25.00']);

        $this->postNotification(
            $this->notification($payment, 'CONFIRMED', '2026-10-12T10:20:00+02:00'),
            $account,
        )->assertStatus(202);

        $order->refresh();

        $this->assertSame('35.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertNotNull($payment->fresh()->accounted_at);
    }

    public function test_overpayment_is_flagged_for_review_without_changing_order_balance(): void
    {
        $account = $this->account();
        $order = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '90.00',
        ]);
        $payment = $this->payment($account, $order, ['amount' => '20.00']);

        $this->postNotification(
            $this->notification($payment, 'CONFIRMED', '2026-10-12T10:25:00+02:00'),
            $account,
        )->assertStatus(202);

        $order->refresh();
        $payment->refresh();

        $this->assertSame('90.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertTrue($payment->needs_review);
        $this->assertSame('overpayment_detected', $payment->safe_error_code);
        $this->assertNull($payment->accounted_at);
        $this->assertSame(0, $order->events()->where('event_type', 'paynow_payment_registered')->count());
    }

    public function test_older_notification_cannot_reverse_a_confirmed_payment(): void
    {
        $account = $this->account();
        $order = $this->order(['total_gross' => '100.00']);
        $payment = $this->payment($account, $order, ['amount' => '100.00']);

        $this->postNotification(
            $this->notification($payment, 'CONFIRMED', '2026-10-12T10:30:00+02:00'),
            $account,
        )->assertStatus(202);
        $this->postNotification(
            $this->notification($payment, 'PENDING', '2026-10-12T10:29:00+02:00'),
            $account,
        )->assertStatus(202);

        $this->assertSame(PayNowPaymentStatus::Confirmed, $payment->fresh()->status);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
        $this->assertFalse($payment->fresh()->needs_review);
        $this->assertSame(1, $order->events()->where('event_type', 'paynow_payment_registered')->count());
    }

    public function test_payment_recovery_may_confirm_a_new_provider_id_for_the_same_external_id(): void
    {
        $account = $this->account();
        $order = $this->order(['total_gross' => '100.00']);
        $payment = $this->payment($account, $order, ['amount' => '100.00']);
        $payload = [
            'paymentId' => 'PBLA-RECOVERED-2',
            'externalId' => $payment->external_id,
            'status' => 'CONFIRMED',
            'modifiedAt' => '2026-10-12T10:35:00+02:00',
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->postNotification($body, $account)->assertStatus(202);

        $this->assertSame('PBLA-RECOVERED-2', $payment->fresh()->provider_payment_id);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
    }

    public function test_unknown_but_valid_notification_is_retained_without_changing_an_order(): void
    {
        $account = $this->account();
        $payload = [
            'paymentId' => 'PBLA-UNKNOWN',
            'externalId' => 'unknown-external-id',
            'status' => 'CONFIRMED',
            'modifiedAt' => '2026-10-12T10:40:00+02:00',
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->postNotification($body, $account)->assertStatus(202);

        $this->assertDatabaseHas('paynow_payment_events', [
            'paynow_payment_id' => null,
            'provider_payment_id' => 'PBLA-UNKNOWN',
            'external_id' => 'unknown-external-id',
            'status' => 'CONFIRMED',
        ]);
    }

    public function test_reconciliation_confirms_and_accounts_payment_when_webhook_is_unavailable(): void
    {
        $account = $this->account();
        $order = $this->order(['total_gross' => '80.00']);
        $payment = $this->payment($account, $order, ['amount' => '80.00']);

        Http::fake([
            'https://api.sandbox.paynow.pl/v3/payments/PBLA-ORIGINAL-1/status' => Http::response([
                'paymentId' => 'PBLA-ORIGINAL-1',
                'status' => 'CONFIRMED',
            ]),
        ]);

        $this->assertSame(1, app(PayNowReconciliationService::class)->reconcileDue());
        $this->assertSame(0, app(PayNowReconciliationService::class)->reconcileDue());

        $this->assertSame('80.00', $order->fresh()->paid_amount);
        $this->assertNotNull($payment->fresh()->accounted_at);
        $this->assertDatabaseHas('paynow_payment_events', [
            'paynow_payment_id' => $payment->getKey(),
            'source' => 'status_check',
            'status' => 'CONFIRMED',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.sandbox.paynow.pl/v3/payments/PBLA-ORIGINAL-1/status'
            && ($request->header('Api-Key')[0] ?? null) === $account->api_key
            && filled($request->header('Signature')[0] ?? null));
        Http::assertSentCount(1);
    }

    public function test_order_view_exposes_confirmed_payments_and_paynow_notification_url(): void
    {
        $account = $this->account();
        $order = $this->order();
        $payment = $this->payment($account, $order, [
            'status' => PayNowPaymentStatus::Confirmed,
            'confirmed_at' => now(),
            'accounted_at' => now(),
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Płatności')
            ->assertSee('Zarejestrowano płatność PayNow')
            ->assertSee($payment->provider_payment_id)
            ->assertSee('Zaksięgowano automatycznie');

        $this->get(route('integrations.paynow.edit'))
            ->assertOk()
            ->assertSee('Adres powiadomień PayNow')
            ->assertSee(route('api.paynow.notifications'));
    }

    private function account(): PayNowAccount
    {
        return PayNowAccount::query()->create([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'PayNow test',
            'environment' => PayNowEnvironment::Sandbox,
            'api_key' => 'test-api-key',
            'signature_key' => 'test-signature-key',
            'is_active' => false,
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'external_id' => 'PAYNOW-REGISTRATION-ORDER',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
            'customer_email' => 'customer@example.test',
        ], $overrides));
    }

    private function payment(PayNowAccount $account, Order $order, array $overrides = []): PayNowPayment
    {
        return PayNowPayment::query()->create(array_merge([
            'paynow_account_id' => $account->getKey(),
            'order_id' => $order->getKey(),
            'amount' => '100.00',
            'currency' => 'PLN',
            'external_id' => 'nex-order-'.$order->getKey().'-payment-1',
            'provider_payment_id' => 'PBLA-ORIGINAL-1',
            'idempotency_key' => 'paynow-registration-payment-1',
            'status' => PayNowPaymentStatus::Pending,
            'buyer_email_snapshot' => 'customer@example.test',
            'description' => 'Płatność za zamówienie',
        ], $overrides));
    }

    private function notification(
        PayNowPayment $payment,
        string $status,
        string $modifiedAt,
    ): string {
        return json_encode([
            'paymentId' => $payment->provider_payment_id,
            'externalId' => $payment->external_id,
            'status' => $status,
            'modifiedAt' => $modifiedAt,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function postNotification(
        string $body,
        PayNowAccount $account,
        ?string $signature = null,
    ) {
        $signature ??= base64_encode(hash_hmac(
            'sha256',
            $body,
            (string) $account->signature_key,
            true,
        ));

        return $this->call(
            'POST',
            route('api.paynow.notifications'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
