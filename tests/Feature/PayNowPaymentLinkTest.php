<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowSignatureService;
use Tests\TestCase;

class PayNowPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_link_for_the_remaining_order_balance_without_registering_payment(): void
    {
        $account = $this->readyAccount([
            'continue_url' => 'https://shop.example.test/payment-return',
        ]);
        $order = $this->order([
            'total_gross' => '150.10',
            'paid_amount' => '50.10',
        ]);
        $paymentUrl = 'https://paywall.sandbox.paynow.pl/PBLA-TEST-100';

        Http::fake([
            'https://api.sandbox.paynow.pl/v3/payments/links' => Http::response([
                'url' => $paymentUrl,
                'paymentId' => 'PBLA-TEST-100',
                'status' => 'NEW',
            ], 201),
        ]);

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertOk()
            ->assertJsonPath('url', fn (string $url): bool => str_starts_with($url, url('/pay/')) && strlen(basename($url)) === 32)
            ->assertJsonPath('reused', false)
            ->assertJsonPath('amount', '100.00')
            ->assertJsonPath('currency', 'PLN');

        $payment = PayNowPayment::query()->sole();
        $order->refresh();

        $this->assertSame('100.00', $payment->amount);
        $this->assertSame($paymentUrl, $payment->payment_url);
        $this->assertSame(PayNowPaymentStatus::Created, $payment->status);
        $this->assertSame('50.10', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNotSame($paymentUrl, DB::table('paynow_payments')->value('payment_url'));
        $this->assertDatabaseHas('paynow_payment_events', [
            'paynow_payment_id' => $payment->getKey(),
            'source' => 'api_response',
            'provider_payment_id' => 'PBLA-TEST-100',
            'status' => PayNowPaymentStatus::Created->value,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'paynow_payment_link_created',
        ]);
        $this->assertStringNotContainsString(
            $paymentUrl,
            (string) DB::table('order_events')->where('order_id', $order->getKey())->value('payload'),
        );

        Http::assertSent(function (Request $request) use ($account, $order): bool {
            $body = $request->body();
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $idempotencyKey = $request->header('Idempotency-Key')[0] ?? '';
            $externalId = PayNowPayment::query()->value('external_id');
            $expectedSignature = app(PayNowSignatureService::class)->generateV3(
                (string) $account->api_key,
                (string) $account->signature_key,
                $idempotencyKey,
                body: $body,
            );

            return $request->method() === 'POST'
                && $request->url() === 'https://api.sandbox.paynow.pl/v3/payments/links'
                && ($request->header('Api-Key')[0] ?? null) === $account->api_key
                && ($request->header('Signature')[0] ?? null) === $expectedSignature
                && $payload['amount'] === 10000
                && $payload['currency'] === 'PLN'
                && $payload['externalId'] === $externalId
                && $payload['description'] === 'Płatność za zamówienie nr '.$order->getKey()
                && $payload['buyer']['email'] === 'customer@example.test'
                && $payload['validityTime'] === 604800
                && $payload['continueUrl'] === 'https://shop.example.test/payment-return';
        });
        Http::assertSentCount(1);
    }

    public function test_it_reuses_a_still_valid_link_and_does_not_duplicate_history(): void
    {
        $this->readyAccount();
        $order = $this->order();
        $paymentUrl = 'https://paywall.sandbox.paynow.pl/PBLA-REUSED';

        Http::fake([
            '*' => Http::response([
                'url' => $paymentUrl,
                'paymentId' => 'PBLA-REUSED',
                'status' => 'PENDING',
            ], 201),
        ]);

        $first = $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertOk()
            ->assertJsonPath('reused', false);

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertOk()
            ->assertJsonPath('url', $first->json('url'))
            ->assertJsonPath('reused', true);

        Http::assertSentCount(1);
        $this->assertSame(1, PayNowPayment::query()->count());
        $this->assertDatabaseCount('paynow_payment_events', 1);
        $this->assertSame(
            1,
            DB::table('order_events')
                ->where('order_id', $order->getKey())
                ->where('event_type', 'paynow_payment_link_created')
                ->count(),
        );
    }

    public function test_it_rejects_an_unready_account_and_ineligible_order_data_without_calling_paynow(): void
    {
        $account = $this->readyAccount(['is_active' => false]);
        $order = $this->order();
        Http::fake();

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_link');

        $account->forceFill(['is_active' => true])->save();
        $order->forceFill(['currency' => 'EUR'])->save();

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_link');

        $order->forceFill([
            'currency' => 'PLN',
            'customer_email' => null,
        ])->save();

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_link');

        $order->forceFill([
            'customer_email' => 'customer@example.test',
            'paid_amount' => '125.50',
            'payment_status' => 'paid',
        ])->save();

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_link');

        Http::assertNothingSent();
        $this->assertDatabaseCount('paynow_payments', 0);
    }

    public function test_provider_validation_failure_is_recorded_without_leaking_raw_provider_details(): void
    {
        $this->readyAccount();
        $order = $this->order();
        $privateProviderMessage = 'internal-provider-debug-secret';

        Http::fake([
            '*' => Http::response([
                'errors' => [[
                    'errorType' => 'VALIDATION_ERROR',
                    'message' => $privateProviderMessage,
                ]],
            ], 400),
        ]);

        $response = $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'PayNow odrzucił dane linku do płatności.');

        $this->assertStringNotContainsString($privateProviderMessage, $response->getContent());
        $this->assertDatabaseHas('paynow_payments', [
            'order_id' => $order->getKey(),
            'safe_error_code' => 'VALIDATION_ERROR',
            'safe_error_message' => 'PayNow odrzucił dane linku do płatności.',
        ]);
        $this->assertDatabaseCount('paynow_payment_events', 0);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'paynow_payment_link_created',
        ]);
    }

    public function test_connection_failure_returns_a_retryable_error_and_preserves_the_idempotency_record(): void
    {
        $this->readyAccount();
        $order = $this->order();

        Http::fake(['*' => Http::failedConnection()]);

        $this->postJson(route('orders.paynow.payment-link', $order))
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Nie udało się połączyć z PayNow. Spróbuj ponownie.');

        $this->assertDatabaseHas('paynow_payments', [
            'order_id' => $order->getKey(),
            'safe_error_code' => 'connection_error',
        ]);
        $this->assertDatabaseCount('paynow_payment_events', 0);
    }

    private function readyAccount(array $overrides = []): PayNowAccount
    {
        return PayNowAccount::query()->create(array_merge([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'PayNow test',
            'environment' => PayNowEnvironment::Sandbox,
            'api_key' => 'secret-api-key',
            'signature_key' => 'secret-signature-key',
            'is_active' => true,
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
            'last_tested_at' => now(),
            'last_test_status' => PayNowConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ], $overrides));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'external_id' => 'PAYNOW-LINK-ORDER',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '125.50',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
            'customer_email' => 'customer@example.test',
        ], $overrides));
    }
}
