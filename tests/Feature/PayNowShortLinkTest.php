<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Models\PayNowShortLink;
use Modules\Integrations\PayNow\Services\PayNowReturnUrlService;
use Modules\Integrations\PayNow\Services\PayNowShortLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayNowShortLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = 'https://paywall.sandbox.paynow.pl/TEST-123?token=provider-private-token';

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_copying_produces_the_same_short_url_and_keeps_tokens_encrypted_and_hidden(): void
    {
        $payment = $this->payment();
        $before = $payment->getAttributes();
        URL::forceRootUrl('https://oms.multi-click.pl');
        URL::forceScheme('https');
        $first = $this->url($payment);
        $this->assertMatchesRegularExpression('~^https://oms\.multi-click\.pl/pay/[A-Za-z0-9_-]{32}$~D', $first);
        $this->assertSame($first, $this->url($payment));
        $link = PayNowShortLink::query()->sole();
        $this->assertSame(hash('sha256', basename($first)), $link->token_hash);
        $this->assertNotSame(basename($first), DB::table('paynow_short_links')->value('token'));
        $this->assertArrayNotHasKey('token', $link->toArray());
        $this->assertArrayNotHasKey('token_hash', $link->toArray());
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(self::TARGET, $payment->fresh()->payment_url);
        $this->assertSame(app(PayNowReturnUrlService::class)->expiresAt($payment)->toDateTimeString(), $link->expires_at->toDateTimeString());
        Http::assertNothingSent();
    }

    public function test_get_head_and_repeated_previews_redirect_without_api_or_business_writes(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment);
        $before = $payment->fresh()->getAttributes();
        $orderBefore = $payment->order->getAttributes();
        $linkBefore = PayNowShortLink::query()->sole()->getAttributes();
        foreach (['GET', 'HEAD', 'GET'] as $method) {
            $response = $this->call($method, $url)->assertRedirect(self::TARGET)
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame($orderBefore, $payment->order->fresh()->getAttributes());
        $this->assertSame($linkBefore, PayNowShortLink::query()->sole()->getAttributes());
        $this->assertDatabaseCount('paynow_payments', 1);
        $this->assertDatabaseCount('paynow_payment_events', 0);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public function test_query_parameters_cannot_choose_a_destination_or_forge_confirmation(): void
    {
        $payment = $this->payment();
        $this->get($this->url($payment).'?url=https://evil.example&paymentStatus=CONFIRMED&paymentId=OTHER')
            ->assertRedirect(self::TARGET);
        $this->assertSame(PayNowPaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame('0.00', $payment->order->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_paid_attempt_goes_to_the_existing_signed_confirmation_with_transaction_id(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment);
        $payment->update(['status' => PayNowPaymentStatus::Confirmed, 'accounted_at' => now()]);
        $payment->order->update(['paid_amount' => '100.00', 'payment_status' => 'paid']);
        $response = $this->get($url)->assertRedirect();
        $return = $response->headers->get('Location');
        $this->assertTrue(URL::hasValidSignature(Request::create($return)));
        $this->get($return)->assertOk()->assertSee('Płatność została dokonana')->assertSee('TEST-123')
            ->assertDontSee('customer@example.test')->assertDontSee('id="retry-form"', false);
        Http::assertNothingSent();
    }

    #[DataProvider('statusPageScenarios')]
    public function test_ineligible_attempts_never_redirect_back_to_an_active_paywall(string $scenario): void
    {
        $payment = $this->payment();
        $url = $this->url($payment);
        if ($scenario === 'expired') {
            $this->travelTo($payment->valid_until->addHour());
        } elseif ($scenario === 'changed balance') {
            $payment->order->update(['paid_amount' => '25.00']);
        } elseif ($scenario === 'paid elsewhere') {
            $payment->order->update(['paid_amount' => '100.00', 'payment_status' => 'paid']);
        } elseif ($scenario === 'changed currency') {
            $payment->order->update(['currency' => 'EUR']);
        } elseif ($scenario === 'changed email') {
            $payment->order->update(['customer_email' => 'other@example.test']);
        } elseif ($scenario === 'newer attempt') {
            $this->anotherAttempt($payment, PayNowPaymentStatus::Pending);
        } elseif ($scenario === 'older unresolved') {
            $latest = $this->anotherAttempt($payment, PayNowPaymentStatus::Pending);
            $latest->update(['provider_payment_id' => 'LATEST-123', 'payment_url' => self::TARGET, 'valid_until' => now()->addDays(7)]);
            $url = $this->url($latest);
        } elseif ($scenario === 'review') {
            $payment->update(['needs_review' => true]);
        } else {
            $payment->update(['status' => $scenario]);
        }
        $response = $this->get($url)->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith(route('paynow.return'), $target);
        $this->assertTrue(URL::hasValidSignature(Request::create($target)));
        $this->assertDatabaseCount('paynow_payment_events', 0);
        Http::assertNothingSent();
    }

    public static function statusPageScenarios(): array
    {
        return array_map(fn ($value) => [$value], ['expired', 'changed balance', 'paid elsewhere', 'changed currency', 'changed email',
            'newer attempt', 'older unresolved', 'review', 'REJECTED', 'ERROR', 'EXPIRED', 'ABANDONED']);
    }

    #[DataProvider('blockedLinks')]
    public function test_invalid_revoked_or_changed_identities_are_neutral_and_do_not_leak_details(string $scenario): void
    {
        $payment = $this->payment();
        $url = $this->url($payment);
        $link = PayNowShortLink::query()->sole();
        if ($scenario === 'unknown') {
            $url = route('paynow.short-link', ['token' => str_repeat('z', 32)]);
        } elseif ($scenario === 'revoked') {
            $link->update(['revoked_at' => now()]);
        } elseif ($scenario === 'expired access') {
            $this->travel(9)->days();
        } elseif ($scenario === 'removed payment') {
            $payment->delete();
        } elseif ($scenario === 'deleted order') {
            $payment->order->delete();
        } elseif ($scenario === 'cancelled') {
            $payment->order->update(['status' => Order::STATUS_CANCELLED]);
        } elseif ($scenario === 'disabled account') {
            $payment->account->update(['is_active' => false]);
        } elseif ($scenario === 'environment') {
            $payment->account->update(['environment' => PayNowEnvironment::Production]);
        } elseif ($scenario === 'credentials') {
            $payment->account->update(['api_key' => 'new-key']);
        } elseif ($scenario === 'payment amount') {
            $payment->update(['amount' => '200.00']);
        } elseif ($scenario === 'payment link') {
            $payment->update(['payment_url' => 'https://evil.example/steal']);
        } elseif ($scenario === 'payment id') {
            $payment->update(['provider_payment_id' => 'OTHER-123']);
        } elseif ($scenario === 'access expiry') {
            $link->update(['expires_at' => now()->addYear()]);
        } elseif ($scenario === 'target fingerprint') {
            $link->update(['target_hash' => str_repeat('a', 64)]);
        } else {
            $other = $this->anotherAttempt($payment, PayNowPaymentStatus::Pending);
            $link->update(['paynow_payment_id' => $other->id]);
        }
        $response = $this->get($url)->assertNotFound()->assertDontSee('100,00')->assertDontSee('TEST-123')
            ->assertDontSee('customer@example.test')->assertDontSee('provider-private-token')
            ->assertDontSee('secret-api-key')->assertDontSee('Lista zamówień');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public static function blockedLinks(): array
    {
        return array_map(fn ($value) => [$value], ['unknown', 'revoked', 'expired access', 'removed payment', 'deleted order', 'cancelled',
            'disabled account', 'environment', 'credentials', 'payment amount', 'payment link', 'payment id', 'access expiry', 'target fingerprint', 'reassigned link']);
    }

    #[DataProvider('unsafeTargets')]
    public function test_only_https_provider_urls_in_the_correct_environment_are_allowed(string $target): void
    {
        $payment = $this->payment(['payment_url' => $target]);
        $this->get($this->url($payment))->assertNotFound();
        Http::assertNothingSent();
    }

    public static function unsafeTargets(): array
    {
        return array_map(fn ($value) => [$value], [
            'https://evil.example/pay', 'https://paynow.pl.evil.example/pay', 'http://paywall.sandbox.paynow.pl/pay',
            'https://user:password@paywall.sandbox.paynow.pl/pay', 'https://paywall.sandbox.paynow.pl:444/pay',
            'https://paywall.paynow.pl/pay', 'https://paywall.sandbox.paynow.pl/pay#secret',
            "https://paywall.sandbox.paynow.pl/pay\r\nLocation:https://evil.example", 'https://evil.example\\@paywall.sandbox.paynow.pl/pay',
        ]);
    }

    public function test_production_link_cannot_be_confused_with_sandbox(): void
    {
        $payment = $this->payment(['payment_url' => 'https://paywall.paynow.pl/TEST-123?token=secret']);
        $payment->account->update(['environment' => PayNowEnvironment::Production]);
        $this->get($this->url($payment))->assertRedirect('https://paywall.paynow.pl/TEST-123?token=secret');
        Http::assertNothingSent();
    }

    public function test_schema_not_installed_blocks_generation_before_creating_any_payment(): void
    {
        $payment = $this->payment();
        Schema::drop('paynow_short_links');
        $this->postJson(route('orders.paynow.payment-link', $payment->order))->assertServiceUnavailable();
        $this->get(route('paynow.short-link', ['token' => str_repeat('a', 32)]))->assertNotFound();
        $this->assertDatabaseCount('paynow_payments', 1);
        Http::assertNothingSent();
    }

    public function test_database_uniqueness_and_orphan_retention_and_safe_rollback(): void
    {
        $payment = $this->payment();
        $this->url($payment);
        $row = PayNowShortLink::query()->sole()->getAttributes();
        unset($row['id']);
        DB::table('paynow_short_links')->insertOrIgnore($row);
        $this->assertDatabaseCount('paynow_short_links', 1);
        $migration = require database_path('migrations/2026_10_26_030000_create_paynow_short_links_table.php');
        try {
            $migration->down();
            $this->fail('Existing links must be retained.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('paynow_short_links', 1);
        }
        $payment->delete();
        $this->assertNull(PayNowShortLink::query()->sole()->paynow_payment_id);
    }

    public function test_short_link_has_rate_limiting_and_does_not_accept_post(): void
    {
        $url = $this->url($this->payment());
        $this->post($url)->assertMethodNotAllowed();
        for ($i = 0; $i < 60; $i++) {
            $this->get($url)->assertRedirect(self::TARGET);
        }
        $this->get($url)->assertTooManyRequests();
        Http::assertNothingSent();
    }

    public function test_malformed_tokens_do_not_match_the_public_route(): void
    {
        foreach ([str_repeat('a', 31), str_repeat('a', 33), '123', str_repeat('a', 31).'.'] as $token) {
            $this->get('/pay/'.$token)->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_revocation_is_not_bypassed_by_copying_the_same_payment_again(): void
    {
        $payment = $this->payment();
        $this->url($payment);
        $link = PayNowShortLink::query()->sole();
        $link->update(['revoked_at' => now()]);
        try {
            $this->url($payment);
            $this->fail('Revoked links must not be regenerated implicitly.');
        } catch (ValidationException) {
            $this->assertSame($link->token_hash, PayNowShortLink::query()->sole()->token_hash);
            $this->assertNotNull(PayNowShortLink::query()->sole()->revoked_at);
        }
        Http::assertNothingSent();
    }

    public function test_mysql_migration_has_short_unique_constraints_and_null_on_delete(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new class(fn () => throw new \RuntimeException('No live MySQL allowed.'), 'schema_preview') extends MySqlConnection
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
            $migration = require database_path('migrations/2026_10_26_030000_create_paynow_short_links_table.php');
            $sql = implode("\n", array_column($connection->pretend(fn () => $migration->up()), 'query'));
        } finally {
            Schema::swap($original);
        }
        $this->assertStringContainsString('`paynow_short_payment_fk`', $sql);
        $this->assertStringContainsString('on delete set null', $sql);
        $this->assertStringContainsString('`paynow_short_payment_unique`(`paynow_payment_id`)', $sql);
        $this->assertStringContainsString('`paynow_short_token_unique`(`token_hash`)', $sql);
        preg_match_all('/(?:constraint|unique|index) `([^`]+)`/', $sql, $names);
        foreach ($names[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    private function url(PayNowPayment $payment): string
    {
        return app(PayNowShortLinkService::class)->forPayment($payment);
    }

    private function anotherAttempt(PayNowPayment $payment, PayNowPaymentStatus $status): PayNowPayment
    {
        return PayNowPayment::query()->create([
            'paynow_account_id' => $payment->paynow_account_id, 'order_id' => $payment->order_id,
            'amount' => '100.00', 'currency' => 'PLN', 'external_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'status' => $status,
            'buyer_email_snapshot' => 'customer@example.test', 'description' => 'Other attempt',
        ]);
    }

    private function payment(array $attributes = []): PayNowPayment
    {
        $account = PayNowAccount::query()->create([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY, 'name' => 'PayNow',
            'environment' => PayNowEnvironment::Sandbox, 'api_key' => 'secret-api-key', 'signature_key' => 'secret-signature-key',
            'is_active' => true, 'last_test_status' => PayNowConnectionTestStatus::Success,
        ]);
        $order = Order::query()->create(['source' => 'manual', 'status' => Order::STATUS_NEW,
            'currency' => 'PLN', 'total_gross' => '100.00', 'paid_amount' => '0.00',
            'payment_status' => 'unpaid', 'customer_email' => 'customer@example.test']);

        return PayNowPayment::query()->create($attributes + [
            'paynow_account_id' => $account->id, 'order_id' => $order->id,
            'external_id' => 'nex-order-'.$order->id.'-'.Str::uuid(), 'provider_payment_id' => 'TEST-123',
            'idempotency_key' => (string) Str::uuid(), 'status' => PayNowPaymentStatus::Pending,
            'amount' => '100.00', 'currency' => 'PLN', 'payment_url' => self::TARGET,
            'buyer_email_snapshot' => 'customer@example.test', 'description' => 'Payment for order '.$order->id,
            'valid_until' => now()->addDays(7),
        ])->fresh();
    }
}
