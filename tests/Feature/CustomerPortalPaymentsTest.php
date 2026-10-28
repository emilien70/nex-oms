<?php

namespace Tests\Feature;

use App\Events\OrderPaymentRegistered;
use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowReturnUrlService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPortalPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://paywall.sandbox.paynow.pl/ABCD-123-456-789?token=private';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Event::fake([OrderPaymentRegistered::class]);
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_migration_defaults_are_disabled_and_get_never_creates_settings(): void
    {
        $this->get(route('customer-portal.settings'))->assertOk()->assertSee('Płatności PayNow');
        $this->assertDatabaseCount('customer_portal_settings', 0);
        $settings = PortalSetting::query()->create([])->refresh();
        $this->assertFalse($settings->paynow_enabled);
        $this->assertFalse($settings->paynow_retry_enabled);
        $this->assertNull($settings->paynow_sources);
        Http::assertNothingSent();
    }

    public function test_payment_settings_are_explicit_validated_and_older_forms_preserve_them(): void
    {
        $settings = $this->settings();
        $this->put(route('customer-portal.settings.update'), $this->settingsPayload() + [
            'paynow_settings_present' => 1, 'paynow_enabled' => 1, 'paynow_retry_enabled' => 1,
            'paynow_sources' => ['manual', 'prestashop'],
        ])->assertRedirect(route('customer-portal.settings'));
        $this->assertTrue($settings->fresh()->paynow_retry_enabled);
        $this->put(route('customer-portal.settings.update'), $this->settingsPayload(2))->assertRedirect();
        $this->assertSame(['manual', 'prestashop'], $settings->fresh()->paynow_sources);
        $this->putJson(route('customer-portal.settings.update'), $this->settingsPayload(3) + [
            'paynow_settings_present' => 1, 'paynow_enabled' => 1, 'paynow_retry_enabled' => 1,
            'paynow_sources' => ['allegro'],
        ])->assertUnprocessable();
        $this->assertSame(3, $settings->fresh()->revision);
    }

    public function test_missing_stage_two_migration_keeps_portal_readable_but_payments_fail_closed(): void
    {
        $order = $this->order();
        $url = $this->url($order);
        Schema::table('customer_portal_settings', fn ($table) => $table->dropColumn(['paynow_enabled', 'paynow_retry_enabled', 'paynow_sources']));
        $this->get($url)->assertOk()->assertDontSee('name="intent"', false);
        $this->get(route('customer-portal.settings'))->assertOk()->assertSee('Płatności wymagają migracji PORTAL.2.');
        $this->put(route('customer-portal.settings.update'), $this->settingsPayload())->assertRedirect();
        $this->put(route('customer-portal.settings.update'), $this->settingsPayload(2) + ['paynow_settings_present' => 1])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_get_and_preview_are_read_only_and_never_disclose_provider_url_or_credentials(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $before = $payment->fresh()->getAttributes();
        $orderBefore = $payment->order->fresh()->getAttributes();
        $this->get($url)->assertOk()->assertSee('Kontynuuj płatność')->assertSee('Sprawdź status płatności')
            ->assertDontSee('Ponów płatność')->assertDontSee(self::PROVIDER_URL)->assertDontSee('secret-api')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('customer-portal.preview', $payment->order))->assertOk()->assertDontSee('name="intent"', false);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame($orderBefore, $payment->order->fresh()->getAttributes());
        $this->assertDatabaseCount('paynow_short_links', 0);
        $this->assertDatabaseCount('paynow_payment_events', 0);
        $this->assertDatabaseCount('order_events', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_pay_continues_the_same_attempt_through_existing_short_link_without_api(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $response = $this->postAction($url, 'pay')->assertStatus(303)->assertHeader('Referrer-Policy', 'no-referrer');
        $shortUrl = $response->headers->get('Location');
        $this->assertStringContainsString('/pay/', $shortUrl);
        $this->get($shortUrl)->assertRedirect(self::PROVIDER_URL);
        $this->assertDatabaseCount('paynow_payments', 1);
        $this->assertSame('0.00', $payment->order->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_absent_attempt_cannot_be_created_by_customer_even_with_forged_post_fields(): void
    {
        $this->settings();
        $order = $this->order();
        $url = $this->url($order);
        $this->get($url)->assertOk()->assertDontSee('name="intent"', false);
        foreach (['pay', 'status', 'retry'] as $action) {
            $this->post($url.'/platnosc/'.$action, ['intent' => str_repeat('a', 64), 'amount' => 1, 'order_id' => 9])->assertStatus(303);
        }
        $this->assertDatabaseCount('paynow_payments', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('sourceScenarios')]
    public function test_order_sources_require_explicit_allowlist(string $source, ?array $allowed, bool $visible): void
    {
        $payment = $this->payment(orderAttributes: ['source' => $source]);
        PortalSetting::query()->sole()->update(['paynow_sources' => $allowed]);
        $response = $this->get($this->url($payment->order->fresh()))->assertOk();
        $visible ? $response->assertSee('Kontynuuj płatność') : $response->assertDontSee('name="intent"', false);
        Http::assertNothingSent();
    }

    public static function sourceScenarios(): array
    {
        return [['manual', null, true], ['prestashop', null, false], ['prestashop', ['prestashop'], true],
            ['allegro', ['allegro'], false], ['manual', [], false], ['unknown', ['unknown'], false]];
    }

    #[DataProvider('changedContexts')]
    public function test_stale_forms_reject_changes_before_any_provider_request(string $change): void
    {
        $payment = $this->payment();
        $order = $payment->order;
        $url = $this->url($order);
        $form = $this->form($url, 'status');
        match ($change) {
            'total' => $order->update(['total_gross' => '200.00']),
            'paid' => $order->update(['paid_amount' => '1.00']),
            'email' => $order->update(['customer_email' => 'another@example.test']),
            'currency' => $order->update(['currency' => 'EUR']),
            'cancelled' => $order->update(['status' => Order::STATUS_CANCELLED]),
            'cod' => $order->update(['cash_on_delivery' => true]),
            'trash' => $order->delete(),
            'disabled' => PortalSetting::query()->sole()->update(['paynow_enabled' => false]),
            'revision' => PortalSetting::query()->sole()->increment('revision'),
            'source' => $order->update(['source' => 'allegro']),
            'inactive' => $payment->account->update(['is_active' => false]),
            'credentials' => $payment->account->update(['api_key' => 'new-secret']),
            'provider-id' => $payment->update(['provider_payment_id' => 'IJKL-123-456-789']),
            'target' => $payment->update(['payment_url' => 'https://example.test/unsafe']),
            'review' => $payment->update(['needs_review' => true]),
            'confirmed' => $payment->update(['status' => 'CONFIRMED']),
            'revoked' => app(PortalAccessService::class)->revoke($order, 1),
            'reactivated' => $this->reactivate($order),
            'expired' => PortalLink::query()->sole()->update(['expires_at' => now()->subSecond()]),
            'newer' => $this->anotherAttempt($payment),
        };
        $response = $this->post($form['url'], ['intent' => $form['intent']]);
        $this->assertContains($response->status(), [303, 404]);
        Http::assertNothingSent();
        $this->assertSame($change === 'paid' ? '1.00' : '0.00', $order->fresh()->paid_amount);
        Event::assertNotDispatched(OrderPaymentRegistered::class);
    }

    public static function changedContexts(): array
    {
        return array_map(fn ($name) => [$name], ['total', 'paid', 'email', 'currency', 'cancelled', 'cod', 'trash',
            'disabled', 'revision', 'source', 'inactive', 'credentials', 'provider-id', 'target', 'review',
            'confirmed', 'revoked', 'reactivated', 'expired', 'newer']);
    }

    private function reactivate(Order $order): void
    {
        $access = app(PortalAccessService::class);
        $access->revoke($order, 1);
        $access->activate($order, 2);
    }

    #[DataProvider('failedStatuses')]
    public function test_retry_requires_fresh_failure_and_repeated_stale_post_cannot_create_another_attempt(string $status): void
    {
        $payment = $this->payment(['status' => $status]);
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        $url = $this->url($payment->order);
        $form = $this->form($url, 'retry');
        $this->fakeProvider($status);
        $response = $this->post($form['url'], ['intent' => $form['intent']])->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertRedirect(self::PROVIDER_URL);
        $this->post($form['url'], ['intent' => $form['intent']])->assertStatus(303)->assertRedirect($url);
        $this->assertDatabaseCount('paynow_payments', 2);
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'POST'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['amount'] === 10000);
        $this->assertSame('0.00', $payment->order->fresh()->paid_amount);
    }

    public static function failedStatuses(): array
    {
        return [['REJECTED'], ['ERROR'], ['EXPIRED']];
    }

    public function test_retry_is_off_by_default_and_abandoned_or_locally_expired_pending_attempt_never_offers_it(): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        $url = $this->url($payment->order);
        $this->get($url)->assertOk()->assertDontSee('Ponów płatność');
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        foreach (['ABANDONED', 'PENDING'] as $status) {
            $payment->update(['status' => $status, 'valid_until' => now()->subSecond()]);
            $this->get($url)->assertOk()->assertDontSee('Ponów płatność')->assertDontSee('Kontynuuj płatność');
        }
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeAttempts')]
    public function test_review_unaccounted_confirmation_and_older_unresolved_attempts_block_payment(string $status, bool $review): void
    {
        $older = $this->payment(['status' => $status, 'needs_review' => $review]);
        $this->anotherAttempt($older);
        $this->get($this->url($older->order))->assertOk()->assertDontSee('name="intent"', false);
        Http::assertNothingSent();
    }

    public static function unsafeAttempts(): array
    {
        return [['PENDING', false], ['ABANDONED', false], ['CONFIRMED', false], ['REJECTED', true]];
    }

    public function test_status_uses_existing_single_accounting_path_and_paid_view_displays_transaction_id(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $form = $this->form($url, 'status');
        $this->fakeProvider('CONFIRMED');
        $this->post($form['url'], ['intent' => $form['intent']])->assertStatus(303)->assertRedirect($url);
        $this->post($form['url'], ['intent' => $form['intent']])->assertStatus(303);
        $this->assertSame('100.00', $payment->order->fresh()->paid_amount);
        $this->assertNotNull($payment->fresh()->accounted_at);
        $this->get($url)->assertOk()->assertSee('Opłacone')->assertSee('ABCD-123-456-789')->assertDontSee('name="intent"', false);
        $this->assertSame(1, $payment->order->events()->where('event_type', 'paynow_payment_registered')->count());
        Event::assertDispatchedTimes(OrderPaymentRegistered::class, 1);
        Http::assertSentCount(1);
    }

    public function test_provider_unavailability_does_not_create_retry_or_modify_order(): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        $url = $this->url($payment->order);
        Http::fake(['*' => Http::failedConnection()]);
        $this->postAction($url, 'retry')->assertStatus(303)->assertRedirect($url);
        $this->assertDatabaseCount('paynow_payments', 1);
        $this->assertSame('0.00', $payment->order->fresh()->paid_amount);
    }

    public function test_identity_or_grant_change_during_api_check_prevents_status_record_and_accounting(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        Http::fake(function () use ($payment) {
            app(PortalAccessService::class)->revoke($payment->order, 1);

            return Http::response(['paymentId' => $payment->provider_payment_id, 'status' => 'CONFIRMED']);
        });
        $this->postAction($url, 'status')->assertStatus(303);
        $this->assertSame('0.00', $payment->order->fresh()->paid_amount);
        $this->assertSame(PayNowPaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('paynow_payment_events', 0);
        Event::assertNotDispatched(OrderPaymentRegistered::class);
    }

    public function test_no_external_http_is_performed_while_portal_database_locks_are_held(): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        $transactionLevel = DB::transactionLevel();
        $this->fakeProvider('REJECTED', function () use ($transactionLevel): void {
            $this->assertSame($transactionLevel, DB::transactionLevel());
        });
        $this->postAction($this->url($payment->order), 'retry')->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 2);
    }

    #[DataProvider('retryProviderStates')]
    public function test_retry_never_creates_another_payment_when_fresh_state_is_uncertain_or_confirmed(string $state): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        $this->fakeProvider($state);
        $this->postAction($this->url($payment->order), 'retry')->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 1);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertSame($state === 'CONFIRMED' ? '100.00' : '0.00', $payment->order->fresh()->paid_amount);
    }

    public static function retryProviderStates(): array
    {
        return [['CONFIRMED'], ['ABANDONED'], ['PENDING']];
    }

    public function test_revocation_during_retry_verification_stops_before_preparing_new_attempt(): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        $this->fakeProvider('REJECTED', fn () => app(PortalAccessService::class)->revoke($payment->order, 1));
        $this->postAction($this->url($payment->order), 'retry')->assertStatus(303);
        $this->assertDatabaseCount('paynow_payments', 1);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_unsafe_provider_redirect_is_not_exposed_to_the_browser(): void
    {
        $payment = $this->payment(['payment_url' => 'https://example.test/untrusted']);
        $url = $this->url($payment->order);
        $this->postAction($url, 'pay')->assertStatus(303)->assertRedirect($url);
        $this->assertDatabaseCount('paynow_short_links', 0);
        Http::assertNothingSent();
    }

    public function test_payment_return_from_another_payment_or_after_key_change_has_no_portal_capability(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $this->postAction($url, 'pay')->assertStatus(303);
        $other = $this->anotherAttempt($payment);
        $other->update(['order_id' => $this->order()->id]);
        $this->get(app(PayNowReturnUrlService::class)->forPayment($other))->assertOk()->assertDontSee('Powrót do zamówienia');
        $payment->account->update(['api_key' => 'changed-api']);
        $this->get(app(PayNowReturnUrlService::class)->forPayment($payment))->assertOk()->assertDontSee('Powrót do zamówienia');
        Http::assertNothingSent();
    }

    public function test_configured_payment_settings_cannot_be_rolled_back_and_discarded(): void
    {
        $this->settings();
        $migration = require base_path('database/migrations/2026_10_26_050000_add_paynow_to_customer_portal_settings.php');
        try {
            $migration->down();
            $this->fail('Configured settings must be retained.');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasColumn('customer_portal_settings', 'paynow_enabled'));
            $this->assertTrue(PortalSetting::query()->sole()->paynow_enabled);
        }
    }

    public function test_paynow_return_link_is_browser_scoped_and_revocation_removes_it(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $return = app(PayNowReturnUrlService::class)->forPayment($payment);
        $this->get($return)->assertOk()->assertDontSee('Powrót do zamówienia');
        $this->postAction($url, 'pay')->assertStatus(303);
        $this->get($return)->assertStatus(303)->assertRedirect($url);
        app(PortalAccessService::class)->revoke($payment->order, 1);
        $this->get($return)->assertOk()->assertDontSee('Powrót do zamówienia')->assertDontSee($url, false);
        Http::assertNothingSent();
    }

    public function test_portal_intent_cannot_be_replayed_for_another_order_or_action(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $form = $this->form($url, 'pay');
        $another = $this->url($this->order());
        $this->post($another.'/platnosc/pay', ['intent' => $form['intent']])->assertStatus(303);
        $this->post($url.'/platnosc/status', ['intent' => $form['intent']])->assertStatus(303);
        Http::assertNothingSent();
        $this->assertDatabaseCount('paynow_short_links', 0);
    }

    public function test_return_on_customer_domain_redirects_only_the_browser_that_started_in_the_portal(): void
    {
        $payment = $this->payment();
        PortalSetting::query()->sole()->update(['customer_domain' => 'klient.example.test']);
        $url = $this->url($payment->order);
        $return = app(PayNowReturnUrlService::class)->forPayment($payment);
        $this->get($return)->assertOk()->assertDontSee($url, false);
        $this->postAction($url, 'pay')->assertStatus(303);
        $this->get($return)->assertStatus(303)->assertRedirect($url);
        $this->flushSession();
        $this->get($return)->assertOk()->assertDontSee($url, false);
        Http::assertNothingSent();
    }

    public function test_actions_are_post_only_require_csrf_and_have_a_rate_limit(): void
    {
        $payment = $this->payment();
        $url = $this->url($payment->order);
        $form = $this->form($url, 'status');
        $this->get($form['url'])->assertMethodNotAllowed();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($form['url'], ['intent' => $form['intent']])->assertStatus(419);
        $this->fakeProvider();
        for ($i = 0; $i < 6; $i++) {
            $this->withSession(['_token' => 'csrf'])->post($form['url'], ['intent' => $form['intent'], '_token' => 'csrf'])->assertStatus(303);
        }
        $this->post($form['url'], ['intent' => $form['intent'], '_token' => 'csrf'])->assertTooManyRequests();
    }

    public function test_held_order_retry_lock_blocks_simultaneous_retry_without_provider_calls(): void
    {
        $payment = $this->payment(['status' => 'REJECTED']);
        PortalSetting::query()->sole()->update(['paynow_retry_enabled' => true]);
        $lock = Cache::lock('paynow-customer-retry-'.$payment->order_id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->postAction($this->url($payment->order), 'retry')->assertStatus(303);
            Http::assertNothingSent();
            $this->assertDatabaseCount('paynow_payments', 1);
        } finally {
            $lock->release();
        }
    }

    private function form(string $url, string $action): array
    {
        $response = $this->get($url)->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[@action="'.$url.'/platnosc/'.$action.'"]');
        $this->assertCount(1, $forms, 'Expected an eligible payment action: '.$action);
        $form = $forms->item(0);

        return ['url' => $form->getAttribute('action'), 'intent' => $xpath->query('.//input[@name="intent"]', $form)->item(0)->getAttribute('value')];
    }

    private function postAction(string $url, string $action)
    {
        $form = $this->form($url, $action);

        return $this->post($form['url'], ['intent' => $form['intent']]);
    }

    private function url(Order $order): string
    {
        return app(PortalAccessService::class)->issue($order);
    }

    private function settings(): PortalSetting
    {
        return PortalSetting::query()->create(['is_enabled' => true, 'paynow_enabled' => true]);
    }

    private function settingsPayload(int $revision = 1): array
    {
        return ['expected_revision' => $revision, 'is_enabled' => 1, 'store_name' => 'Sklep', 'accent_color' => '#0075c9',
            'availability_days' => 90, 'show_images' => 1, 'show_address' => 0, 'show_invoices' => 1, 'show_corrections' => 1, 'show_proformas' => 0];
    }

    private function payment(array $attributes = [], array $orderAttributes = []): PayNowPayment
    {
        $this->settings();
        $account = PayNowAccount::query()->create([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY, 'name' => 'PayNow',
            'environment' => PayNowEnvironment::Sandbox, 'api_key' => 'secret-api', 'signature_key' => 'secret-signature',
            'is_active' => true, 'last_test_status' => PayNowConnectionTestStatus::Success,
            'link_validity_seconds' => 604800, 'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ]);
        $order = $this->order($orderAttributes);

        return PayNowPayment::query()->create($attributes + ['order_id' => $order->id, 'paynow_account_id' => $account->id,
            'amount' => '100.00', 'currency' => 'PLN', 'external_id' => 'nex-order-'.$order->id.'-'.Str::uuid(),
            'provider_payment_id' => 'ABCD-123-456-789', 'idempotency_key' => Str::uuid(), 'status' => 'PENDING',
            'buyer_email_snapshot' => $order->customer_email, 'description' => 'Płatność za zamówienie nr '.$order->id,
            'valid_until' => now()->addDays(7), 'payment_url' => self::PROVIDER_URL]);
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create($attributes + ['source' => 'manual', 'status' => Order::STATUS_NEW, 'currency' => 'PLN',
            'total_gross' => '100.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid', 'customer_email' => 'customer@example.test']);
    }

    private function anotherAttempt(PayNowPayment $payment): PayNowPayment
    {
        $another = $payment->replicate(['provider_payment_id', 'external_id', 'idempotency_key']);
        $another->fill(['provider_payment_id' => 'IJKL-123-456-789', 'external_id' => 'nex-'.Str::uuid(), 'idempotency_key' => Str::uuid(),
            'status' => 'PENDING', 'needs_review' => false, 'accounted_at' => null])->save();

        return $another;
    }

    private function fakeProvider(string $status = 'PENDING', ?callable $beforeResponse = null): void
    {
        Http::fake(function (Request $request) use ($status, $beforeResponse) {
            $beforeResponse?->__invoke();
            if ($request->method() === 'POST') {
                return Http::response(['url' => self::PROVIDER_URL, 'paymentId' => 'IJKL-123-456-789', 'status' => 'PENDING'], 201);
            }
            preg_match('~/payments/([^/]+)/status$~', $request->url(), $matches);

            return Http::response(['paymentId' => $matches[1], 'status' => $status]);
        });
    }
}
