<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowOrderLink;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Models\PayNowShortLink;
use Modules\Integrations\PayNow\Services\PayNowPaymentLinkService;
use Modules\Integrations\PayNow\Services\PayNowSignatureService;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertJsonPath('url', fn (string $url): bool => preg_match('~^'.preg_quote(url('/zamowienie/'.$order->id), '~').'/[A-Za-z0-9]{10}/pay$~D', $url) === 1)
            ->assertJsonPath('reused', false)
            ->assertJsonPath('amount', '100.00')
            ->assertJsonPath('currency', 'PLN');

        $payment = PayNowPayment::query()->sole();
        $order->refresh();

        $this->assertSame('100.00', $payment->amount);
        $this->assertSame('NEX-'.$order->id.'-1', $payment->external_id);
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

    public function test_checkout_forwards_only_on_continue_and_keeps_the_same_address_for_a_replacement(): void
    {
        $this->readyAccount();
        $order = $this->order(['billing_name' => 'Prywatna nazwa']);
        $firstTarget = 'https://paywall.sandbox.paynow.pl/FIRST?token=provider-first';
        $secondTarget = 'https://paywall.sandbox.paynow.pl/SECOND?token=provider-second';
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => $firstTarget, 'paymentId' => 'FIRST', 'status' => 'NEW'], 201)
            ->push(['url' => $secondTarget, 'paymentId' => 'SECOND', 'status' => 'NEW'], 201);

        $action = route('orders.paynow.payment-link', $order);
        $address = $this->postJson($action)->assertOk()->json('url');
        $stable = PayNowOrderLink::query()->sole();
        $first = PayNowPayment::query()->sole();
        $legacyToken = PayNowShortLink::query()->sole()->token;
        $this->assertNotSame($stable->token, $legacyToken);
        $legacyOrderUrl = route('paynow.short-link', ['token' => $stable->token]);
        $this->assertNotSame($stable->token, DB::table('paynow_order_links')->value('token'));
        $this->assertArrayNotHasKey('token', $stable->toArray());
        $this->get($address)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('125,50 PLN')->assertSee('Zapłać')->assertSee('id="pay-seconds">5', false)
            ->assertSee('href="'.parse_url($address, PHP_URL_PATH).'?continue=1"', false)
            ->assertDontSee($firstTarget)->assertDontSee('Prywatna nazwa')->assertDontSee('customer@example.test');
        $this->call('HEAD', $address.'?continue=1')->assertOk();
        $this->get($address.'?continue=1&url=https://evil.example')->assertRedirect($firstTarget);
        $this->get(route('paynow.short-link', ['token' => $legacyToken]))->assertRedirect($firstTarget);
        $this->get($legacyOrderUrl.'?continue=1')->assertRedirect($firstTarget);
        $this->assertSame('0.00', $order->fresh()->paid_amount);
        Http::assertSentCount(1);

        $this->travelTo($first->valid_until->addMinute());
        $this->postJson($action)->assertUnprocessable()->assertJsonValidationErrors('payment_link');
        $this->get($address.'?continue=1')->assertOk()->assertDontSee('id="pay-continue"', false);
        Http::assertSentCount(1);

        $first->update(['status' => PayNowPaymentStatus::Expired]);
        $this->get($address)->assertOk()->assertSee('Próba płatności wygasła');
        $this->postJson($action)->assertOk()->assertJsonPath('url', $address)->assertJsonPath('reused', false);
        $this->get($address.'?continue=1')->assertRedirect($secondTarget);
        $this->get($legacyOrderUrl.'?continue=1')->assertRedirect($secondTarget);
        $this->get(route('paynow.short-link', ['token' => $legacyToken]))->assertRedirect();
        $this->assertCount(2, $order->payNowPayments()->get());
        $this->assertSame(['NEX-'.$order->id.'-1', 'NEX-'.$order->id.'-2'], $order->payNowPayments()->oldest('id')->pluck('external_id')->all());
        $this->assertDatabaseCount('paynow_order_links', 1);

        $order->update(['paid_amount' => '125.50']);
        $this->get($address.'?continue=1')->assertOk()->assertSee('Zamówienie zostało już opłacone')
            ->assertDontSee('id="pay-continue"', false);
        Http::assertSentCount(2);
    }

    public function test_continue_rechecks_balance_customer_archive_and_order_status(): void
    {
        $this->readyAccount();
        $order = $this->order();
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/CHECK', 'paymentId' => 'CHECK', 'status' => 'PENDING'], 201);
        $action = route('orders.paynow.payment-link', $order);
        $address = $this->postJson($action)->assertOk()->json('url');
        $this->get($address)->assertOk()->assertSee('id="pay-continue"', false);

        $order->update(['paid_amount' => '50.00']);
        $this->get($address.'?continue=1')->assertOk()->assertDontSee('id="pay-continue"', false);
        $this->postJson($action)->assertUnprocessable();
        $order->update(['paid_amount' => '0.00', 'customer_email' => 'other@example.test']);
        $this->get($address.'?continue=1')->assertNotFound()->assertDontSee('125,50');
        $order->forceFill(['customer_email' => 'customer@example.test', 'archived_at' => now()])->save();
        $this->get($address.'?continue=1')->assertNotFound();
        $order->forceFill(['archived_at' => null, 'status' => Order::STATUS_CANCELLED])->save();
        $this->get($address.'?continue=1')->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_new_customer_gets_a_new_address_after_the_old_attempt_is_closed(): void
    {
        $this->readyAccount();
        $order = $this->order();
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/ORIGINAL', 'paymentId' => 'ORIGINAL', 'status' => 'NEW'], 201)
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/UPDATED', 'paymentId' => 'UPDATED', 'status' => 'NEW'], 201);
        $action = route('orders.paynow.payment-link', $order);
        $address = $this->postJson($action)->assertOk()->json('url');
        PayNowPayment::query()->sole()->update(['status' => PayNowPaymentStatus::Expired]);
        $order->update(['customer_email' => 'new@example.test']);
        $this->get($address)->assertNotFound();
        $updated = $this->postJson($action)->assertOk()->json('url');
        $this->assertNotSame($address, $updated);
        $this->get($updated.'?continue=1')->assertRedirect('https://paywall.sandbox.paynow.pl/UPDATED');
        $this->get($address.'?continue=1')->assertNotFound();
        $this->assertDatabaseCount('paynow_order_links', 1);
    }

    public function test_customer_domain_can_serve_the_checkout_without_authorizing_the_order_portal(): void
    {
        PortalSetting::query()->create(['singleton_key' => PortalSetting::KEY, 'customer_domain' => 'client.example.test']);
        $this->readyAccount();
        $order = $this->order();
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/DOMAIN', 'paymentId' => 'DOMAIN', 'status' => 'NEW'], 201);

        $address = $this->postJson(route('orders.paynow.payment-link', $order))->assertOk()->json('url');
        $this->assertStringStartsWith('https://client.example.test/zamowienie/'.$order->id.'/', $address);
        $this->get($address)->assertOk()->assertSee('Zapłać');
        $landingPath = substr((string) parse_url($address, PHP_URL_PATH), 0, -4);
        $this->get('https://client.example.test'.$landingPath)->assertNotFound();
        $this->get('https://client.example.test/'.$order->id.'/'.basename($landingPath))->assertNotFound();
        $this->get('https://client.example.test/orders/'.$order->id)->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_checkout_requires_the_correct_order_and_case_sensitive_code_and_limits_guesses(): void
    {
        $this->readyAccount();
        $order = $this->order();
        $other = $this->order(['external_id' => 'OTHER-CHECKOUT-ORDER']);
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/ORIGINAL', 'paymentId' => 'ORIGINAL', 'status' => 'NEW'], 201);
        $address = $this->postJson(route('orders.paynow.payment-link', $order))->assertOk()->json('url');
        $code = basename(substr((string) parse_url($address, PHP_URL_PATH), 0, -4));
        $otherAddress = route('paynow.order-link', ['orderNumber' => $other->id, 'code' => $code]);
        $this->get($otherAddress.'?continue=1')->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee('125,50');
        $this->post($address)->assertMethodNotAllowed();

        $wrongCode = strtr($code[0], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
        $wrongCode = ($wrongCode === $code[0] ? (string) (((int) $code[0] + 1) % 10) : $wrongCode).substr($code, 1);
        $wrongAddress = route('paynow.order-link', ['orderNumber' => $order->id, 'code' => $wrongCode]);
        for ($i = 0; $i < 19; $i++) {
            $this->get($wrongAddress)->assertNotFound();
        }
        $this->get($address)->assertOk();
        $this->get($wrongAddress)->assertTooManyRequests();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('paynow_payments', 1);
    }

    public function test_new_attempt_number_counts_old_attempts_and_preserves_their_identifiers(): void
    {
        $account = $this->readyAccount();
        $order = $this->order();
        $oldId = 'nex-order-'.$order->id.'-historical-id';
        $order->payNowPayments()->create([
            'paynow_account_id' => $account->id, 'external_id' => $oldId, 'idempotency_key' => 'historical-key',
            'amount' => '125.50', 'currency' => 'PLN', 'status' => PayNowPaymentStatus::Expired,
            'buyer_email_snapshot' => $order->customer_email, 'description' => 'Historical attempt',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/NEW-LINK', 'paymentId' => 'NEW-LINK', 'status' => 'NEW'], 201);

        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->data()['externalId'] === 'NEX-'.$order->id.'-2');
        $this->assertSame([$oldId, 'NEX-'.$order->id.'-2'], $order->payNowPayments()->oldest('id')->pluck('external_id')->all());
    }

    public function test_copy_during_an_in_flight_creation_reuses_the_reserved_identifier_and_request_key(): void
    {
        $this->readyAccount();
        $order = $this->order();
        $requests = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($order, &$requests) {
            $this->assertSame('https://api.sandbox.paynow.pl/v3/payments/links', $request->url());
            $requests[] = [$request->data()['externalId'], $request->header('Idempotency-Key')[0]];
            if (count($requests) === 1) {
                app(PayNowPaymentLinkService::class)->createOrReuse($order);
            }

            return Http::response(['url' => 'https://paywall.sandbox.paynow.pl/SAME', 'paymentId' => 'SAME', 'status' => 'NEW'], 201);
        });

        $this->postJson(route('orders.paynow.payment-link', $order))->assertOk();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertSame('NEX-'.$order->id.'-1', $requests[0][0]);
        $this->assertDatabaseCount('paynow_payments', 1);
        $this->assertDatabaseCount('paynow_payment_events', 1);
    }

    public function test_missing_order_link_table_blocks_new_attempts_but_preserves_legacy_routes(): void
    {
        $this->readyAccount();
        $order = $this->order();
        Http::fake();
        Schema::drop('paynow_order_links');
        $this->postJson(route('orders.paynow.payment-link', $order))->assertServiceUnavailable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paynow_payments', 0);
        $this->get(route('paynow.short-link', ['token' => str_repeat('A', 32)]))->assertNotFound();
    }

    #[DataProvider('unsafeCheckoutChanges')]
    public function test_checkout_never_redirects_when_its_payment_or_identity_has_become_unsafe(string $change): void
    {
        $account = $this->readyAccount();
        $order = $this->order();
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/SAFE', 'paymentId' => 'SAFE', 'status' => 'NEW'], 201);
        $address = $this->postJson(route('orders.paynow.payment-link', $order))->assertOk()->json('url');
        $payment = PayNowPayment::query()->sole();
        $shortLink = PayNowShortLink::query()->sole();
        $orderLink = PayNowOrderLink::query()->sole();
        match ($change) {
            'currency' => $order->update(['currency' => 'EUR']),
            'review' => $payment->update(['needs_review' => true]),
            'unaccounted' => $payment->update(['status' => PayNowPaymentStatus::Confirmed]),
            'abandoned' => $payment->update(['status' => PayNowPaymentStatus::Abandoned]),
            'revoked' => $shortLink->update(['revoked_at' => now()]),
            'credentials' => $account->update(['signature_key' => 'other-key']),
            'environment' => $account->update(['environment' => PayNowEnvironment::Production]),
            'inactive' => $account->update(['is_active' => false]),
            'provider-target' => $payment->update(['payment_url' => 'https://evil.example']),
            'token' => DB::table('paynow_order_links')->where('id', $orderLink->id)->update(['token' => 'corrupt']),
            'deleted' => $order->delete(),
            'unbound' => $orderLink->update(['paynow_short_link_id' => null]),
        };
        $result = $this->get($address.'?continue=1&paymentStatus=CONFIRMED&paymentId=OTHER&url=https://evil.example');
        $this->assertContains($result->getStatusCode(), [200, 404]);
        $result->assertDontSee('id="pay-continue"', false)->assertDontSee('customer@example.test');
        $this->assertNull($result->headers->get('Location'));
        $this->assertSame('0.00', $order->fresh()->paid_amount);
        $this->assertDatabaseCount('paynow_payments', 1);
        $this->assertDatabaseCount('paynow_payment_events', 1);
        Http::assertSentCount(1);
    }

    public static function unsafeCheckoutChanges(): array
    {
        return array_map(fn ($change) => [$change], [
            'currency', 'review', 'unaccounted', 'abandoned', 'revoked', 'credentials', 'environment',
            'inactive', 'provider-target', 'token', 'deleted', 'unbound',
        ]);
    }

    public function test_checkout_link_is_unique_retains_history_and_cannot_be_reassigned(): void
    {
        $this->readyAccount();
        $order = $this->order();
        Http::fakeSequence('https://api.sandbox.paynow.pl/v3/payments/links')
            ->push(['url' => 'https://paywall.sandbox.paynow.pl/HISTORY', 'paymentId' => 'HISTORY', 'status' => 'NEW'], 201);
        $address = $this->postJson(route('orders.paynow.payment-link', $order))->assertOk()->json('url');
        $link = PayNowOrderLink::query()->sole();
        $copy = $link->getAttributes();
        unset($copy['id']);
        DB::table('paynow_order_links')->insertOrIgnore($copy);
        $this->assertDatabaseCount('paynow_order_links', 1);
        $migration = require database_path('migrations/2026_11_17_020000_create_paynow_order_links_table.php');
        try {
            $migration->down();
            $this->fail('Existing payment URLs must be retained.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('udostępnione linki', $exception->getMessage());
        }
        try {
            $link->update(['order_id' => $this->order(['external_id' => 'OTHER'])->id]);
            $this->fail('A payment URL must not be reassigned.');
        } catch (\LogicException) {
            $this->assertSame($order->id, $link->fresh()->order_id);
        }
        $order->forceDelete();
        $this->assertNull($link->fresh()->order_id);
        $this->get($address)->assertNotFound();
        $this->assertDatabaseCount('paynow_payments', 1);
        $this->assertDatabaseCount('paynow_order_links', 1);
    }

    public function test_order_link_migration_is_additive_and_has_explicit_mysql_history_relations(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new class(fn () => throw new \RuntimeException('No live MySQL allowed.'), 'preview') extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.46';
            }
        };
        Schema::swap($connection->getSchemaBuilder());
        try {
            $migration = require database_path('migrations/2026_11_17_020000_create_paynow_order_links_table.php');
            $sql = implode("\n", array_column($connection->pretend(fn () => $migration->up()), 'query'));
        } finally {
            Schema::swap($original);
        }
        $this->assertStringContainsString('create table `paynow_order_links`', $sql);
        $this->assertStringContainsString('constraint `pol_order_fk` foreign key (`order_id`) references `orders` (`id`) on delete set null', $sql);
        $this->assertStringContainsString('constraint `pol_short_fk` foreign key (`paynow_short_link_id`) references `paynow_short_links` (`id`) on delete set null', $sql);
        $this->assertStringContainsString('unique `pol_order_unique`(`order_id`)', $sql);
        $this->assertStringContainsString('unique `pol_token_unique`(`token_hash`)', $sql);
        $this->assertStringNotContainsString('update ', $sql);
        $this->assertStringNotContainsString('drop ', $sql);
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
