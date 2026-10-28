<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\CustomerPortal\Services\PortalUrlService;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Modules\Integrations\PayNow\Services\PayNowReturnUrlService;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class CustomerPortalPrivacyTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        Storage::fake('local');
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_defaults_mask_personal_data_without_creating_settings_on_get(): void
    {
        $this->get('/settings/customer-portal')->assertOk()->assertSeeText('Domena strony dla klientów');
        $this->assertDatabaseCount('customer_portal_settings', 0);
        $order = $this->order();
        $settings = PortalSetting::query()->sole();
        $this->assertTrue($settings->mask_personal_data);
        $this->assertTrue($settings->verify_documents);
        $this->assertSame('any', $settings->verification_method);
        $settings->update(['show_address' => true]);
        $before = $order->getAttributes();
        $response = $this->get($this->url($order))->assertOk()->assertSeeText('Wyświetl pełne dane');
        foreach (['Angelika Kozik', 'Dostawcza', 'Fakturowa', '1234567890', 'klient@example.test'] as $private) {
            $response->assertDontSee($private);
            $this->assertStringNotContainsString($private, json_encode($response->viewData('data')));
        }
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('order_events', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('acceptedValues')]
    public function test_verification_is_case_insensitive_but_does_not_modify_saved_data(string $value): void
    {
        $order = $this->order(['billing_company_name' => 'Żółty Sklep Sp. z o.o.']);
        PortalSetting::query()->sole()->update(['show_address' => true]);
        $before = $order->getAttributes();
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => $value])->assertStatus(303)->assertRedirect($url);
        $this->get($url)->assertOk()->assertSeeText('Angelika Kozik')->assertSeeText('Dostawcza')->assertSeeText('Żółty Sklep Sp. z o.o.');
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertStringNotContainsString('Angelika', json_encode(session('customer_portal_verifications')));
        Http::assertNothingSent();
    }

    public static function acceptedValues(): array
    {
        return [['angelika kozik'], ['ANGELIKA KOZIK'], ["  Angelika\u{00A0}   Kozik  "],
            ['KLIENT@EXAMPLE.TEST'], [' klient@example.test '], ['ŻÓŁTY SKLEP SP. Z O.O.'],
            ["Z\u{0307}o\u{0301}łty Sklep Sp. z o.o."]];
    }

    #[DataProvider('rejectedValues')]
    public function test_partial_empty_wrong_or_malformed_values_never_unlock_data(mixed $value): void
    {
        $order = $this->order();
        PortalSetting::query()->sole()->update(['show_address' => true]);
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => $value])->assertStatus(422)->assertDontSee('Dostawcza');
        $this->get($url)->assertOk()->assertDontSee('Dostawcza');
        $this->assertNull(session('customer_portal_verifications'));
        $this->assertNull(session('_old_input.portal_value'));
    }

    public static function rejectedValues(): array
    {
        return [['Angelika'], ['Kozik'], ['Kozik Angelika'], ['Anna Nowak'], ['kupujacy'], [''], ['   '],
            [null], [['Angelika Kozik']], [str_repeat('x', 255)], ['klient @example.test'], ['Angelika-Kozik'],
            ["Angelika\0 Kozik"], ['Kowalski Handel.']];
    }

    public function test_diacritics_are_preserved_and_empty_buyer_fields_never_match(): void
    {
        $order = $this->order(['billing_name' => 'Łukasz Żółć', 'billing_company_name' => null, 'customer_email' => null]);
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => 'Lukasz Zolc'])->assertStatus(422);
        $this->post($url.'/weryfikacja', ['portal_value' => ''])->assertStatus(422);
        $this->post($url.'/weryfikacja', ['portal_value' => 'ŁUKASZ ŻÓŁĆ'])->assertStatus(303);
    }

    public function test_configured_verification_method_is_enforced(): void
    {
        $order = $this->order();
        $settings = PortalSetting::query()->sole();
        $settings->update(['verification_method' => 'email']);
        $url = $this->url($order);
        $this->get($url.'/dane')->assertNotFound();
        $this->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertStatus(422);
        $this->post($url.'/weryfikacja', ['portal_value' => 'KLIENT@EXAMPLE.TEST'])->assertStatus(303);
        $settings->update(['verification_method' => 'name_company']);
        $this->post($url.'/weryfikacja', ['portal_value' => 'klient@example.test'])->assertStatus(422);
        $this->post($url.'/weryfikacja', ['portal_value' => 'angelika kozik'])->assertStatus(303);
    }

    public function test_verified_access_expires_after_fifteen_minutes_and_is_order_and_browser_scoped(): void
    {
        $order = $this->order();
        PortalSetting::query()->sole()->update(['show_address' => true]);
        $other = $this->order(['billing_name' => 'Other Buyer']);
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertStatus(303);
        $this->get($this->url($other))->assertOk()->assertDontSee('Other Buyer');
        $this->travel(14)->minutes();
        $this->get($url)->assertOk()->assertSeeText('Angelika Kozik');
        $this->travel(2)->minutes();
        $this->get($url)->assertOk()->assertDontSee('Angelika Kozik');
        $this->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertStatus(303);
        $this->flushSession();
        $this->get($url)->assertOk()->assertDontSee('Angelika Kozik');
    }

    #[DataProvider('grantChanges')]
    public function test_changed_grants_or_personal_data_invalidate_the_session(string $change): void
    {
        $order = $this->order();
        $settings = PortalSetting::query()->sole();
        $settings->update(['show_address' => true]);
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertStatus(303);
        match ($change) {
            'shipping' => $order->update(['shipping_street' => 'New private street']),
            'buyer' => $order->update(['billing_name' => 'New Buyer']),
            'policy' => $settings->update(['verification_method' => 'email']),
            'revision' => $settings->increment('revision'),
            'revoke' => app(PortalAccessService::class)->revoke($order, 1),
            'reactivate' => $this->reactivate($order),
            'disable' => $settings->update(['is_enabled' => false]),
        };
        $response = $this->get($url)->assertDontSee('Angelika Kozik')->assertDontSee('New private street');
        $response->assertStatus(in_array($change, ['revoke', 'disable'], true) ? 404 : 200);
    }

    public static function grantChanges(): array
    {
        return [['shipping'], ['buyer'], ['policy'], ['revision'], ['revoke'], ['reactivate'], ['disable']];
    }

    public function test_pdf_and_head_are_gated_and_admin_download_is_unchanged(): void
    {
        $order = $this->order();
        $invoice = $this->invoice($order);
        $url = $this->url($order).'/dokumenty/'.$invoice->id;
        $before = $invoice->fresh()->getAttributes();
        $counters = DB::table('invoice_number_counters')->get()->toJson();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertSee('name="portal_value"', false);
        $this->head($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->get('/invoices/'.$invoice->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post($this->url($order).'/weryfikacja', ['portal_value' => 'angelika kozik', 'invoice' => (string) $invoice->id])
            ->assertStatus(303)->assertRedirect($url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($counters, DB::table('invoice_number_counters')->get()->toJson());
        $this->travel(16)->minutes();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        Http::assertNothingSent();
    }

    public function test_foreign_documents_and_unsigned_admin_paths_cannot_bypass_the_gate(): void
    {
        $order = $this->order();
        $foreign = $this->invoice($this->order(['billing_name' => 'Other Buyer']));
        $url = $this->url($order);
        $this->get($url.'/dokumenty/'.$foreign->id)->assertNotFound();
        $this->post($url.'/weryfikacja', ['portal_value' => 'angelika kozik', 'invoice' => (string) $foreign->id])->assertNotFound();
        $this->post($url.'/weryfikacja', ['portal_value' => 'angelika kozik', 'return_to' => 'https://evil.example/'])
            ->assertStatus(303)->assertRedirect($url);
        $this->get($url.'/dokumenty/'.$foreign->id)->assertNotFound();
    }

    public function test_protection_flags_are_independent_and_do_not_enable_hidden_sections(): void
    {
        $order = $this->order();
        $invoice = $this->invoice($order);
        $settings = PortalSetting::query()->sole();
        $url = $this->url($order);
        $settings->update(['show_address' => false, 'mask_personal_data' => false]);
        $this->get($url)->assertOk()->assertDontSee('Dostawcza')->assertDontSee('Dane nabywcy');
        $this->get($url.'/dokumenty/'.$invoice->id)->assertOk()->assertSee('name="portal_value"', false);
        $settings->update(['verify_documents' => false, 'show_address' => true, 'mask_personal_data' => true]);
        $this->get($url)->assertOk()->assertDontSee('Dostawcza');
        $this->get($url.'/dokumenty/'.$invoice->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_failed_attempts_are_limited_per_ip_without_permanently_locking_the_order(): void
    {
        $order = $this->order();
        $url = $this->url($order);
        for ($i = 0; $i < 5; $i++) {
            $this->post($url.'/weryfikacja', ['portal_value' => 'wrong'])->assertStatus(422);
        }
        $this->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.55'])
            ->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertStatus(303);
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post($url.'/weryfikacja', ['portal_value' => 'Angelika Kozik'])->assertStatus(303);
    }

    public function test_verification_is_post_only_and_requires_csrf(): void
    {
        $order = $this->order();
        $url = $this->url($order).'/weryfikacja';
        $this->get($url)->assertMethodNotAllowed();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($url, ['portal_value' => 'Angelika Kozik'])->assertStatus(419);
        $this->assertNull(session('customer_portal_verifications'));
    }

    public function test_ten_character_codes_are_case_sensitive_order_scoped_and_old_routes_do_not_work(): void
    {
        Str::createRandomStringsUsing(fn () => 'AbC123xYz9');
        try {
            $order = $this->order();
        } finally {
            Str::createRandomStringsNormally();
        }
        $link = PortalLink::query()->sole();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{10}$/D', $link->token);
        $this->get('/'.$order->id.'/AbC123xYz9')->assertOk();
        $this->get('/'.$order->id.'/abc123xyz9')->assertNotFound();
        $this->get('/'.($order->id + 1).'/AbC123xYz9')->assertNotFound();
        $this->get('/zamowienie/AbC123xYz9')->assertNotFound();
        $this->get('/'.$order->id.'/'.str_repeat('A', 32))->assertNotFound();
        $this->get('/'.$order->id.'/AbC123xYz9/logo')->assertNotFound();
    }

    public function test_random_code_collision_is_retried_without_replacing_another_orders_link(): void
    {
        Str::createRandomStringsUsing(fn () => 'AAAAAAAAAA');
        try {
            $order = $this->order();
        } finally {
            Str::createRandomStringsNormally();
        }
        $link = PortalLink::query()->sole();
        $attempt = 0;
        Str::createRandomStringsUsing(function () use (&$attempt): string {
            return ++$attempt === 1 ? 'AAAAAAAAAA' : 'BBBBBBBBBB';
        });
        try {
            $other = $this->order();
        } finally {
            Str::createRandomStringsNormally();
        }
        $this->assertSame('AAAAAAAAAA', $link->fresh()->token);
        $this->assertSame('BBBBBBBBBB', PortalLink::query()->where('order_id', $other->id)->sole()->token);
        $this->assertTrue(app(PortalAccessService::class)->orderLink($order)['active']);
    }

    public function test_domain_setting_is_normalized_and_old_forms_preserve_privacy_settings(): void
    {
        $this->put('/settings/customer-portal', $this->settingsPayload() + ['customer_domain' => ' KLIENT.EXAMPLE.TEST '])->assertRedirect();
        $settings = PortalSetting::query()->sole();
        $this->assertSame('klient.example.test', $settings->customer_domain);
        $order = $this->order();
        $url = $this->url($order);
        $this->assertMatchesRegularExpression('~^https://klient\.example\.test/'.$order->id.'/[A-Za-z0-9]{10}$~D', $url);
        $this->get('https://klient.example.test/'.basename(dirname($url)).'/'.basename($url))->assertOk();
        $this->put('http://localhost/settings/customer-portal', $this->settingsPayload(1, false))->assertRedirect();
        $this->assertSame('klient.example.test', $settings->fresh()->customer_domain);
        $this->assertTrue($settings->fresh()->mask_personal_data);
        $settings->update(['customer_domain' => 'nowa.example.test']);
        $this->assertStringStartsWith('https://nowa.example.test/', $this->url($order));
        $this->assertSame(basename($url), basename($this->url($order)));
        $this->assertSame('http://localhost', config('app.url'));
    }

    public function test_local_loopback_uses_the_actual_development_port_without_changing_app_url(): void
    {
        $this->app->instance('env', 'local');
        $this->app->instance('request', Request::create('http://127.0.0.1:8000/orders'));
        $urls = app(PortalUrlService::class);
        $this->assertSame('http://127.0.0.1:8000', $urls->base());
        $this->assertSame('http://127.0.0.1:8000/paynow/powrot', app(PayNowReturnUrlService::class)->base());
        $this->assertSame('http://localhost', config('app.url'));
        $this->app->instance('request', Request::create('https://evil.example.test/orders'));
        $this->assertSame('http://localhost', $urls->base());
        PortalSetting::query()->create(['customer_domain' => 'klient.example.test']);
        $this->app->instance('request', Request::create('http://127.0.0.1:8000/orders'));
        $this->assertSame('https://klient.example.test', $urls->base());
        $this->get('http://127.0.0.1:8000/settings/customer-portal')->assertOk();
    }

    public function test_customer_domain_cannot_replace_the_admin_panel_host(): void
    {
        config(['app.url' => 'https://oms.example.test']);
        $this->putJson('/settings/customer-portal', $this->settingsPayload() + ['customer_domain' => 'oms.example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_domain');
        $this->assertDatabaseCount('customer_portal_settings', 0);
    }

    #[DataProvider('invalidDomains')]
    public function test_domain_field_rejects_paths_credentials_ports_ips_and_wildcards(string $domain): void
    {
        $this->putJson('/settings/customer-portal', $this->settingsPayload() + ['customer_domain' => $domain])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_domain');
        $this->assertDatabaseCount('customer_portal_settings', 0);
        Http::assertNothingSent();
    }

    public static function invalidDomains(): array
    {
        return [['https://klient.example.test'], ['klient.example.test/path'], ['user:pass@klient.example.test'],
            ['klient.example.test:443'], ['127.0.0.1'], ['*.example.test'], ['localhost'], ['example.test.'],
            ['-bad.example.test'], ['bad_.example.test'], ["client.example.test\nother.example.test"], ['example.test?x=1']];
    }

    public function test_customer_host_denies_admin_routes_and_uses_separate_host_only_session(): void
    {
        $order = $this->order();
        PortalSetting::query()->sole()->update(['customer_domain' => 'klient.example.test']);
        config(['session.domain' => '.example.test']);
        $response = $this->get($this->url($order))->assertOk();
        $cookies = $response->headers->getCookies();
        $customerCookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'nex_oms_customer_portal_session');
        $this->assertNotNull($customerCookie);
        $this->assertNull($customerCookie->getDomain());
        $this->assertTrue($customerCookie->isSecure());
        foreach (['/', '/orders', '/orders/'.$order->id, '/settings/customer-portal', '/settings/customer-portal/preview/'.$order->id, '/integrations/paynow'] as $path) {
            $this->get('https://klient.example.test'.$path)->assertNotFound();
        }
        $this->post('https://klient.example.test/orders/'.$order->id.'/customer-portal/revoke', ['confirm' => 1, 'expected_generation' => 1])->assertNotFound();
        $this->get('http://localhost/'.$order->id.'/'.basename($this->url($order)))->assertNotFound();
        $this->get('http://localhost/orders/'.$order->id)->assertOk();
        $this->assertNull(PortalLink::query()->sole()->revoked_at);
    }

    public function test_paynow_settings_and_new_signed_returns_use_customer_host_without_changing_webhook_or_custom_url(): void
    {
        $order = $this->order();
        PortalSetting::query()->sole()->update(['customer_domain' => 'klient.example.test']);
        $payment = $this->payment($order);
        $urls = app(PayNowReturnUrlService::class);
        $return = $urls->forPayment($payment);
        $this->assertStringStartsWith('https://klient.example.test/paynow/powrot?', $return);
        $this->assertTrue(URL::hasValidSignature(Request::create($return)));
        $this->get('http://localhost/integrations/paynow')->assertOk()->assertSee('https://klient.example.test/paynow/powrot')
            ->assertSee('http://localhost/api/paynow/v1/notifications')->assertSee('https://shop.example.test/return');
        $this->get($return)->assertOk()->assertDontSee($this->url($order), false)->assertDontSee('Angelika');
        $this->get(str_replace('klient.example.test', 'evil.example.test', $return))->assertNotFound();
        $this->assertSame('https://shop.example.test/return', $payment->account->fresh()->continue_url);
        $this->assertSame('0.00', $order->fresh()->paid_amount);
        Http::assertNothingSent();
    }

    public function test_old_signed_payment_return_keeps_its_origin_and_does_not_expose_portal_without_browser_context(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $before = $payment->fresh()->getAttributes();
        $urls = app(PayNowReturnUrlService::class);
        $legacy = $urls->forPayment($payment);
        PortalSetting::query()->sole()->update(['customer_domain' => 'klient.example.test']);
        $response = $this->get($legacy)->assertOk()->assertDontSee('Angelika')->assertDontSee('Powrót do zamówienia');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        foreach ((new \DOMXPath($dom))->query('//form/@action') as $action) {
            $this->assertStringStartsWith('http://localhost/paynow/powrot/', $action->value);
            $this->assertTrue(URL::hasValidSignature(Request::create($action->value)));
        }
        $this->assertSame($before, $payment->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_legacy_replacement_is_disabled_and_preserves_all_existing_codes(): void
    {
        $orders = [];
        foreach (['active', 'revoked', 'expired', 'buyer'] as $case) {
            $order = $this->order();
            $link = PortalLink::query()->where('order_id', $order->id)->sole();
            $token = Str::random(32);
            PortalLink::withoutEvents(fn () => $link->update(['token' => $token, 'token_hash' => hash('sha256', $token), 'binding_hash' => null]));
            if ($case === 'revoked') {
                $link->update(['revoked_at' => now()]);
            }
            if ($case === 'expired') {
                $link->update(['expires_at' => now()->subMinute()]);
            }
            if ($case === 'buyer') {
                $order->update(['billing_name' => 'Changed Buyer']);
            }
            $orders[$case] = [$order, $link->fresh()->getAttributes()];
        }
        $all = PortalLink::query()->get()->map->getAttributes()->all();
        $this->artisan('customer-portal:replace-legacy-links', ['--dry-run' => true])->assertFailed();
        $this->assertSame($all, PortalLink::query()->get()->map->getAttributes()->all());
        $this->artisan('customer-portal:replace-legacy-links')->assertFailed();
        $this->artisan('customer-portal:replace-legacy-links')->assertFailed();
        foreach ($orders as $case => [$order, $before]) {
            $after = PortalLink::query()->where('order_id', $order->id)->sole();
            $this->assertSame($before, $after->getAttributes());
        }
        Http::assertNothingSent();
    }

    private function reactivate(Order $order): void
    {
        $access = app(PortalAccessService::class);
        $access->revoke($order, 1);
        $access->activate($order, 2);
    }

    public function test_changing_customer_domain_preserves_only_signed_historical_payment_returns(): void
    {
        $order = $this->order();
        $settings = PortalSetting::query()->sole();
        $settings->update(['customer_domain' => 'old-client.example.test']);
        $payment = $this->payment($order);
        $legacy = app(PayNowReturnUrlService::class)->forPayment($payment);
        $oldPortal = $this->url($order);
        $settings->update(['customer_domain' => 'new-client.example.test']);
        $response = $this->get($legacy)->assertOk()->assertDontSee($this->url($order), false);
        $this->assertSame('nex_oms_customer_portal_session', config('session.cookie'));
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        foreach ((new \DOMXPath($dom))->query('//form/@action') as $action) {
            $this->assertStringStartsWith('https://old-client.example.test/paynow/powrot/', $action->value);
            $this->assertTrue(URL::hasValidSignature(Request::create($action->value)));
        }
        $this->get('https://old-client.example.test/orders')->assertNotFound();
        $this->get($oldPortal)->assertNotFound();
        $this->get('https://old-client.example.test/paynow/powrot')->assertNotFound();
        $this->get(str_replace('old-client.example.test', 'forged.example.test', $legacy))->assertNotFound();
        $this->get($this->url($order))->assertOk();
        Http::assertNothingSent();
    }

    public function test_missing_privacy_migration_fails_closed_and_cannot_be_saved_or_rolled_back_silently(): void
    {
        $migration = require database_path('migrations/2026_10_27_010000_add_customer_domain_and_verification_to_portal_settings.php');
        try {
            $migration->down();
            $this->fail('Privacy rollback must be explicit.');
        } catch (\RuntimeException) {
        }
        Schema::table('customer_portal_settings', fn ($table) => $table->dropColumn(['customer_domain', 'mask_personal_data', 'verify_documents', 'verification_method']));
        $order = $this->order();
        $invoice = $this->invoice($order);
        $this->get($this->url($order).'/dokumenty/'.$invoice->id)->assertOk()->assertSee('name="portal_value"', false);
        $this->put('/settings/customer-portal', $this->settingsPayload(1))->assertStatus(503);
    }

    private function order(array $attributes = []): Order
    {
        return $this->createDocumentOrder($attributes + ['billing_name' => 'Angelika Kozik', 'purchased_at' => now(), 'paid_amount' => '0.00'])->fresh();
    }

    private function url(Order $order): string
    {
        return app(PortalAccessService::class)->orderLink($order)['url'];
    }

    private function invoice(Order $order): Invoice
    {
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext(now()->format('Y-m-d H:i:s')));
    }

    private function settingsPayload(int $revision = 0, bool $privacy = true): array
    {
        return ['expected_revision' => $revision, 'is_enabled' => 1, 'store_name' => 'Multi-Click', 'accent_color' => '#0075c9',
            'availability_days' => 90, 'show_images' => 1, 'show_address' => 0, 'show_invoices' => 1, 'show_corrections' => 1, 'show_proformas' => 0]
            + ($privacy ? ['privacy_settings_present' => 1, 'mask_personal_data' => 1, 'verify_documents' => 1, 'verification_method' => 'any'] : []);
    }

    private function payment(Order $order): PayNowPayment
    {
        $account = PayNowAccount::query()->create(['account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY, 'name' => 'PayNow',
            'environment' => 'sandbox', 'api_key' => 'test', 'signature_key' => 'test', 'is_active' => true,
            'last_test_status' => PayNowConnectionTestStatus::Success, 'continue_url' => 'https://shop.example.test/return']);

        return PayNowPayment::query()->create(['paynow_account_id' => $account->id, 'order_id' => $order->id,
            'amount' => '123.00', 'currency' => 'PLN', 'external_id' => (string) Str::uuid(), 'provider_payment_id' => 'ABCD-123-456-789',
            'idempotency_key' => (string) Str::uuid(), 'status' => 'NEW', 'buyer_email_snapshot' => $order->customer_email,
            'description' => 'Order', 'payment_url' => 'https://paywall.sandbox.paynow.pl/example', 'valid_until' => now()->addDay()]);
    }
}
