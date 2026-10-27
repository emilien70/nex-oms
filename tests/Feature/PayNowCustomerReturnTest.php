<?php

namespace Tests\Feature;

use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as ServerRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowPaymentLinkService;
use Modules\Integrations\PayNow\Services\PayNowPaymentStatusService;
use Modules\Integrations\PayNow\Services\PayNowReturnUrlService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayNowCustomerReturnTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_ID = 'ABCD-123-456-789';

    private const RETRY_ID = 'EFGH-123-456-789';

    private const PAY_URL = 'https://paywall.sandbox.paynow.pl/retry-payment';

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap((new Factory)->preventStrayRequests());
        Event::fake([OrderPaymentRegistered::class]);
    }

    public function test_settings_show_the_public_base_address_without_overwriting_a_custom_return(): void
    {
        $account = $this->account(['continue_url' => 'https://shop.example.test/return']);
        $this->get(route('integrations.paynow.edit'))->assertOk()
            ->assertSee('Adres strony powrotu OMS')->assertSee(route('paynow.return'))
            ->assertSee('https://shop.example.test/return');
        $this->assertSame('https://shop.example.test/return', $account->fresh()->continue_url);
        Http::assertNothingSent();
    }

    public function test_new_link_uses_a_stable_signed_return_and_does_not_register_a_payment(): void
    {
        $this->account();
        $order = $this->order();
        $this->fakeProvider();
        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        $payment = PayNowPayment::query()->sole();
        $request = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->sole()[0];
        $return = $request->data()['continueUrl'];
        $this->assertTrue(URL::hasValidSignature(ServerRequest::create($return)));
        $this->assertSame($payment->external_id, ServerRequest::create($return)->query('ref'));
        $this->travel(1)->minutes();
        $this->assertSame($return, $this->url($payment));
        $this->assertSame('0.00', $order->fresh()->paid_amount);
    }

    #[DataProvider('portalDefaultReturns')]
    public function test_new_attempt_uses_the_customer_portal_domain_for_the_default_return(?string $custom): void
    {
        PortalSetting::query()->create(['customer_domain' => 'klient.example.test']);
        $this->account(['continue_url' => $custom]);
        $order = $this->order();
        $this->fakeProvider();
        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        $return = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->sole()[0]->data()['continueUrl'];
        $this->assertStringStartsWith('https://klient.example.test/paynow/powrot?', $return);
        $this->assertTrue(URL::hasValidSignature(ServerRequest::create($return)));
        $this->assertSame('0.00', $order->fresh()->paid_amount);
        $this->get($return)->assertOk()->assertDontSee('Powrót do zamówienia');
    }

    public static function portalDefaultReturns(): array
    {
        return [[null], ['https://klient.example.test/paynow/powrot'], ['http://localhost/paynow/powrot']];
    }

    public function test_copying_the_base_address_into_the_custom_field_still_produces_a_signed_return(): void
    {
        $this->account(['continue_url' => route('paynow.return')]);
        $order = $this->order();
        $this->fakeProvider();
        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && URL::hasValidSignature(ServerRequest::create($request->data()['continueUrl'])));
    }

    public function test_timeout_retry_keeps_the_same_signed_payload_and_idempotency_key(): void
    {
        $this->account();
        $order = $this->order();
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = [$request->body(), $request->header('Idempotency-Key')];
            if (count($requests) === 1) {
                throw new ConnectionException('Simulated timeout');
            }

            return Http::response(['url' => self::PAY_URL, 'paymentId' => self::RETRY_ID, 'status' => 'PENDING'], 201);
        });
        $this->postJson(route('orders.paynow.payment-link', $order))->assertServiceUnavailable();
        $this->travel(2)->minutes();
        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertDatabaseCount('paynow_payments', 1);
    }

    #[DataProvider('earlyConfirmationIds')]
    public function test_a_confirmation_arriving_before_the_link_response_is_not_overwritten(string $confirmedId): void
    {
        $this->account();
        $order = $this->order();
        Http::fake(function (Request $request) use ($confirmedId) {
            $payload = [
                'paymentId' => $confirmedId, 'externalId' => $request->data()['externalId'],
                'status' => 'CONFIRMED', 'modifiedAt' => now()->toIso8601String(),
            ];
            app(PayNowPaymentStatusService::class)->recordWebhook(
                $confirmedId, $payload['externalId'], PayNowPaymentStatus::Confirmed, now(),
                $payload, json_encode($payload, JSON_THROW_ON_ERROR),
            );

            return Http::response(['url' => self::PAY_URL, 'paymentId' => self::RETRY_ID, 'status' => 'PENDING'], 201);
        });
        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        $payment = PayNowPayment::query()->sole();
        $this->assertSame(PayNowPaymentStatus::Confirmed, $payment->status);
        $this->assertSame($confirmedId, $payment->provider_payment_id);
        $this->assertSame($confirmedId !== self::RETRY_ID, $payment->needs_review);
        $this->get($this->url($payment))->assertOk()->assertSee('Płatność została dokonana')->assertSee($confirmedId);
        $this->assertSame('100.00', $order->fresh()->paid_amount);
        Event::assertDispatchedTimes(OrderPaymentRegistered::class, 1);
    }

    public static function earlyConfirmationIds(): array
    {
        return [[self::RETRY_ID], [self::ORIGINAL_ID]];
    }

    public function test_public_base_page_is_neutral_and_never_looks_up_a_payment_from_an_unsigned_id(): void
    {
        $payment = $this->payment();
        $this->get(route('paynow.return', ['paymentId' => $payment->provider_payment_id, 'paymentStatus' => 'CONFIRMED']))
            ->assertOk()->assertSee('Brak danych płatności.')->assertDontSee($payment->provider_payment_id);
        Http::assertNothingSent();
    }

    public function test_confirmed_page_has_transaction_id_but_no_admin_navigation_or_personal_data(): void
    {
        $payment = $this->payment(['status' => PayNowPaymentStatus::Confirmed, 'accounted_at' => now()]);
        $response = $this->get($this->url($payment))->assertOk()
            ->assertSee('Płatność została dokonana')->assertSee(self::ORIGINAL_ID)
            ->assertDontSee('customer@example.test')->assertDontSee('secret-api-key')
            ->assertDontSee('Lista zamówień')->assertDontSee('id="retry-form"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_links_cannot_read_payment_details_or_call_the_provider(string $mode): void
    {
        $payment = $this->payment();
        $url = $this->url($payment);
        if ($mode === 'signature') {
            $url = preg_replace('/signature=[^&]+/', 'signature=invalid', $url);
        } elseif ($mode === 'ref') {
            $url = str_replace(urlencode($payment->external_id), 'another-payment', $url);
        } elseif ($mode === 'extra') {
            $url .= '&order_id=999';
        } elseif ($mode === 'array') {
            $url .= '&paymentId[]=bad';
        } else {
            $this->travel(9)->days();
        }
        $this->get($url)->assertForbidden()->assertDontSee('100,00')->assertDontSee(self::ORIGINAL_ID);
        $this->post($url)->assertMethodNotAllowed();
        Http::assertNothingSent();
    }

    public static function invalidUrls(): array
    {
        return [['signature'], ['ref'], ['extra'], ['array'], ['expired']];
    }

    public function test_return_status_is_not_trusted_and_a_new_recovery_id_waits_for_its_verified_webhook(): void
    {
        $payment = $this->payment();
        $this->get($this->url($payment).'&paymentStatus=CONFIRMED')->assertOk()
            ->assertSee('Oczekujemy na potwierdzenie płatności')->assertDontSee('Płatność została dokonana');
        $this->postJson($this->url($payment, 'paynow.return.status').'&paymentId='.self::RETRY_ID)
            ->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('canRetry', false);
        $this->post($this->url($payment, 'paynow.return.retry').'&paymentId='.self::RETRY_ID)->assertStatus(303);
        $this->assertSame('0.00', $payment->order->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_verified_confirmation_is_accounted_once_even_after_repeated_customer_checks(): void
    {
        $payment = $this->payment();
        $this->fakeProvider([self::ORIGINAL_ID => 'CONFIRMED']);
        $url = $this->url($payment, 'paynow.return.status');
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'confirmed')
            ->assertJsonPath('transactionId', self::ORIGINAL_ID)->assertJsonPath('canRetry', false);
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'confirmed');
        $this->assertSame('100.00', $payment->order->fresh()->paid_amount);
        $this->assertSame(1, $payment->order->events()->where('event_type', 'paynow_payment_registered')->count());
        Event::assertDispatchedTimes(OrderPaymentRegistered::class, 1);
        Http::assertSentCount(1);
    }

    public function test_delayed_confirmation_stays_pending_and_can_later_be_confirmed(): void
    {
        $payment = $this->payment();
        $this->fakeProvider();
        $this->postJson($this->url($payment, 'paynow.return.status'))->assertOk()
            ->assertJsonPath('state', 'pending')->assertJsonPath('canRetry', false)->assertJsonPath('poll', true);
        $this->fakeProvider([self::ORIGINAL_ID => 'CONFIRMED']);
        $this->postJson($this->url($payment, 'paynow.return.status'))->assertOk()->assertJsonPath('state', 'confirmed');
        $this->assertSame('100.00', $payment->order->fresh()->paid_amount);
    }

    #[DataProvider('failedStatuses')]
    public function test_only_verified_failed_payments_offer_retry(string $status): void
    {
        $payment = $this->payment(['status' => $status]);
        $this->get($this->url($payment))->assertOk()->assertSee('Oczekujemy na potwierdzenie płatności');
        $this->fakeProvider([self::ORIGINAL_ID => $status]);
        $this->postJson($this->url($payment, 'paynow.return.status'))->assertOk()
            ->assertJsonPath('state', 'failed')->assertJsonPath('canRetry', true)->assertJsonPath('transactionId', null);
    }

    public static function failedStatuses(): array
    {
        return [['REJECTED'], ['ERROR'], ['EXPIRED']];
    }

    public function test_failed_provider_lookup_does_not_offer_retry_or_create_a_new_link(): void
    {
        $payment = $this->payment(['status' => PayNowPaymentStatus::Rejected]);
        Http::fake(['*' => Http::failedConnection()]);
        $this->postJson($this->url($payment, 'paynow.return.status'))->assertOk()
            ->assertJsonPath('state', 'unavailable')->assertJsonPath('canRetry', false);
        $this->post($this->url($payment, 'paynow.return.retry'))->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_retry_uses_current_balance_and_repeated_click_reuses_the_new_pending_link(): void
    {
        $payment = $this->payment(['status' => PayNowPaymentStatus::Rejected]);
        $payment->order->update(['paid_amount' => '25.00']);
        $this->fakeProvider([self::ORIGINAL_ID => 'REJECTED']);
        $url = $this->url($payment, 'paynow.return.retry');
        $this->post($url)->assertStatus(303)->assertRedirect(self::PAY_URL);
        $this->post($url)->assertStatus(303)->assertRedirect(self::PAY_URL);
        $this->assertDatabaseCount('paynow_payments', 2);
        $this->assertSame('25.00', $payment->order->fresh()->paid_amount);
        $this->assertSame('75.00', PayNowPayment::query()->latest('id')->first()->amount);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data()['amount'] === 7500);
    }

    #[DataProvider('blockedRetryScenarios')]
    public function test_retry_rechecks_all_business_guards(string $scenario): void
    {
        $payment = $this->payment(['status' => PayNowPaymentStatus::Rejected]);
        $status = 'REJECTED';
        if ($scenario === 'paid') {
            $payment->order->update(['paid_amount' => '100.00', 'payment_status' => 'paid']);
        } elseif ($scenario === 'cancelled') {
            $payment->order->update(['status' => Order::STATUS_CANCELLED]);
        } elseif ($scenario === 'trashed') {
            $payment->order->delete();
        } elseif ($scenario === 'missing') {
            $payment->update(['order_id' => null]);
        } elseif ($scenario === 'review') {
            $payment->update(['needs_review' => true]);
        } elseif ($scenario === 'inactive') {
            $payment->account->update(['is_active' => false]);
        } elseif ($scenario === 'currency') {
            $payment->order->update(['currency' => 'EUR']);
        } elseif ($scenario === 'confirmed') {
            $status = 'CONFIRMED';
        } elseif ($scenario === 'abandoned') {
            $status = 'ABANDONED';
        } elseif ($scenario === 'unknown_previous') {
            $older = $payment->replicate(['external_id', 'provider_payment_id', 'idempotency_key']);
            $older->fill(['external_id' => 'older-'.Str::uuid(), 'provider_payment_id' => 'IJKL-123-456-789', 'idempotency_key' => Str::uuid(), 'status' => 'PENDING']);
            $older->save();
            $payment = $older;
        }
        $this->fakeProvider([self::ORIGINAL_ID => $status]);
        $this->post($this->url($payment, 'paynow.return.retry'))->assertStatus(303);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        Event::assertDispatchedTimes(OrderPaymentRegistered::class, $scenario === 'confirmed' ? 1 : 0);
    }

    public static function blockedRetryScenarios(): array
    {
        return array_map(fn (string $name): array => [$name], [
            'paid', 'cancelled', 'trashed', 'missing', 'review', 'inactive', 'currency', 'confirmed', 'abandoned', 'unknown_previous',
        ]);
    }

    public function test_a_held_retry_lock_rejects_a_simultaneous_request_without_provider_calls(): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        $lock = Cache::lock('paynow-customer-retry-'.$payment->order_id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->post($this->url($payment, 'paynow.return.retry'))->assertStatus(303);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_new_attempt_between_verification_and_creation_is_rejected_under_database_locks(): void
    {
        $verified = $this->payment(['status' => 'REJECTED']);
        $newer = $verified->replicate(['external_id', 'provider_payment_id', 'idempotency_key']);
        $newer->fill(['external_id' => 'new-'.Str::uuid(), 'provider_payment_id' => self::RETRY_ID, 'idempotency_key' => Str::uuid(), 'status' => 'PENDING'])->save();
        try {
            app(PayNowPaymentLinkService::class)->createOrReuseForCustomer($verified);
            $this->fail('A stale verification must not create a payment');
        } catch (ValidationException) {
            $this->assertDatabaseCount('paynow_payments', 2);
            Http::assertNothingSent();
        }
    }

    #[DataProvider('changedPaymentFields')]
    public function test_payment_changed_after_verification_cannot_be_used_to_create_a_new_attempt(array $changes): void
    {
        $verified = $this->payment(['status' => 'REJECTED']);
        PayNowPayment::query()->whereKey($verified->id)->update($changes);
        try {
            app(PayNowPaymentLinkService::class)->createOrReuseForCustomer($verified);
            $this->fail('A changed payment must not create another attempt');
        } catch (ValidationException) {
            $this->assertDatabaseCount('paynow_payments', 1);
            Http::assertNothingSent();
        }
    }

    public static function changedPaymentFields(): array
    {
        return [[['status' => 'CONFIRMED']], [['provider_payment_id' => self::RETRY_ID]], [['needs_review' => true]]];
    }

    public function test_pending_link_with_changed_order_balance_cannot_create_an_additional_payment(): void
    {
        $payment = $this->payment();
        $payment->order->update(['total_gross' => '150.00']);
        $this->fakeProvider();
        $this->post($this->url($payment, 'paynow.return.retry'))->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_an_unaccounted_confirmation_on_an_older_attempt_blocks_retry(): void
    {
        $older = $this->payment(['status' => 'CONFIRMED', 'needs_review' => true]);
        $latest = $older->replicate(['external_id', 'provider_payment_id', 'idempotency_key']);
        $latest->fill(['external_id' => 'latest-'.Str::uuid(), 'provider_payment_id' => self::RETRY_ID, 'idempotency_key' => Str::uuid(), 'status' => 'REJECTED', 'needs_review' => false])->save();
        $this->fakeProvider([self::RETRY_ID => 'REJECTED']);
        $this->post($this->url($latest, 'paynow.return.retry'))->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_unsigned_post_and_get_retry_cannot_initiate_payments(): void
    {
        $payment = $this->payment();
        $this->post(route('paynow.return.retry', ['ref' => $payment->external_id]))->assertForbidden();
        $this->get($this->url($payment, 'paynow.return.retry'))->assertMethodNotAllowed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paynow_payments', 1);
    }

    public function test_cancelled_order_cannot_generate_an_internal_payment_link_either(): void
    {
        $this->account();
        $order = $this->order();
        $order->update(['status' => Order::STATUS_CANCELLED]);
        $this->postJson(route('orders.paynow.payment-link', $order))->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paynow_payments', 0);
    }

    public function test_existing_unresolved_older_attempt_blocks_a_new_payment(): void
    {
        $older = $this->payment();
        $latest = $older->replicate(['external_id', 'provider_payment_id', 'idempotency_key']);
        $latest->fill(['external_id' => 'latest-'.Str::uuid(), 'provider_payment_id' => self::RETRY_ID, 'idempotency_key' => Str::uuid(), 'status' => 'REJECTED'])->save();
        $this->fakeProvider([self::RETRY_ID => 'REJECTED']);
        $this->post($this->url($latest, 'paynow.return.retry'))->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_post_actions_require_csrf_and_cannot_reuse_a_signature_from_another_route(): void
    {
        $payment = $this->payment();
        $wrongPath = str_replace('/paynow/powrot?', '/paynow/powrot/status?', $this->url($payment));
        $this->postJson($wrongPath)->assertForbidden();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($this->url($payment, 'paynow.return.retry'))->assertStatus(419);
        $this->post($this->url($payment, 'paynow.return.status'))->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_status_action_also_renders_without_javascript(): void
    {
        $payment = $this->payment();
        $this->fakeProvider([self::ORIGINAL_ID => 'REJECTED']);
        $this->post($this->url($payment, 'paynow.return.status'))->assertOk()
            ->assertSee('Płatność nie została zrealizowana')->assertSee('Ponów płatność');
    }

    public function test_status_requests_have_a_per_payment_rate_limit(): void
    {
        $payment = $this->payment(['status' => 'CONFIRMED', 'accounted_at' => now()]);
        $url = $this->url($payment, 'paynow.return.status');
        for ($i = 0; $i < 12; $i++) {
            $this->postJson($url)->assertOk();
        }
        $this->postJson($url)->assertTooManyRequests();
        Http::assertNothingSent();
    }

    private function url(PayNowPayment $payment, string $route = 'paynow.return'): string
    {
        return app(PayNowReturnUrlService::class)->forPayment($payment, $route);
    }

    private function fakeProvider(array $statuses = []): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(function (Request $request) use ($statuses) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v3/payments/links')) {
                return Http::response(['url' => self::PAY_URL, 'paymentId' => self::RETRY_ID, 'status' => 'PENDING'], 201);
            }
            if (preg_match('~/v3/payments/([^/]+)/status$~', $request->url(), $matches)) {
                return Http::response(['paymentId' => $matches[1], 'status' => $statuses[$matches[1]] ?? 'PENDING']);
            }
            throw new \RuntimeException('Unexpected simulated request');
        });
    }

    private function payment(array $attributes = []): PayNowPayment
    {
        $account = $this->account();
        $order = $this->order();

        return PayNowPayment::query()->create($attributes + [
            'paynow_account_id' => $account->id, 'order_id' => $order->id,
            'external_id' => 'nex-order-'.$order->id.'-'.Str::uuid(), 'provider_payment_id' => self::ORIGINAL_ID,
            'idempotency_key' => (string) Str::uuid(), 'status' => PayNowPaymentStatus::Pending,
            'amount' => '100.00', 'currency' => 'PLN', 'payment_url' => self::PAY_URL,
            'buyer_email_snapshot' => 'customer@example.test', 'description' => 'Płatność za zamówienie nr '.$order->id,
            'valid_until' => now()->addDays(7),
        ]);
    }

    private function account(array $attributes = []): PayNowAccount
    {
        return PayNowAccount::query()->create($attributes + [
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY, 'name' => 'PayNow',
            'environment' => PayNowEnvironment::Sandbox, 'api_key' => 'secret-api-key', 'signature_key' => 'secret-signature-key',
            'is_active' => true, 'last_test_status' => PayNowConnectionTestStatus::Success,
            'link_validity_seconds' => 604800, 'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual', 'status' => Order::STATUS_NEW, 'currency' => 'PLN',
            'total_gross' => '100.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid',
            'customer_email' => 'customer@example.test',
        ]);
    }
}
