<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Services\VonHalskyAccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VonHalskyOrderPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
    }

    public function test_preview_shows_paid_order_addresses_invoice_data_and_comment_without_import(): void
    {
        $account = $this->account();
        Http::fake(['*inpsa/*' => Http::response($this->order())]);
        $this->preview($account)->assertOk()->assertSee('AB12345')->assertSee('Zaakceptowane')
            ->assertSee('Jan Kowalski')->assertSee('Kupujący')->assertSee('Lipowa 5/2')->assertSee('00-001 Warszawa')
            ->assertSee('WAW01M')->assertSee('Produkty testowe')->assertSee('209,98 PLN')->assertSee('219,98 PLN')
            ->assertSee('PAYMENT_TEST')->assertSee('1234567890')->assertSee('Proszę o kontakt.')
            ->assertDontSee('TEST_SECRET')->assertDontSee('TEST_TOKEN')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get(route('integrations.von-halsky.edit', ['tab' => 'orders']))->assertDontSee('Jan Kowalski')->assertDontSee('Proszę o kontakt.');
        foreach (['orders', 'order_items', 'order_events', 'invoices', 'jobs', 'automation_runs', 'integration_api_logs', 'customer_portal_links'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/orders/AB12345') && $request->hasHeader('Authorization', 'Bearer TEST_TOKEN'));
    }

    public function test_created_unpaid_order_without_customer_or_invoice_is_a_valid_preview(): void
    {
        $account = $this->account();
        $order = $this->order();
        unset($order['customer'], $order['invoice']);
        $order['status'] = 'CREATED';
        $order['paymentDetails']['payments'] = [];
        Http::fake(['*' => Http::response($order)]);
        $this->preview($account)->assertOk()->assertSee('Utworzone')
            ->assertSee('Dane kupującego nie są jeszcze dostępne w API.')
            ->assertSee('Brak zarejestrowanej płatności')->assertDontSee('Jan Kowalski');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_external_strings_are_escaped_and_hidden_product_identifiers_are_not_displayed(): void
    {
        $account = $this->account();
        $order = $this->order();
        $order['comment'] = '<script>alert("xss")</script>';
        $order['orderLines'][0]['offer']['product']['name'] = '<img src=x onerror=alert(1)>';
        $order['orderLines'][0]['offer']['product']['ean'] = 'HIDDEN_EAN';
        $order['orderLines'][0]['offer']['product']['sku'] = 'HIDDEN_SKU';
        $order['orderLines'][0]['offer']['offerId'] = 'HIDDEN_OFFER_ID';
        Http::fake(['*' => Http::response($order)]);
        $this->preview($account)->assertOk()->assertSee($order['comment'])->assertSee($order['orderLines'][0]['offer']['product']['name'])
            ->assertDontSee('<script>alert(', false)->assertDontSee('<img src=x', false)
            ->assertDontSee('HIDDEN_EAN')->assertDontSee('HIDDEN_SKU')->assertDontSee('HIDDEN_OFFER_ID');
    }

    #[DataProvider('invalidOrders')]
    public function test_wrong_identity_or_malformed_order_is_rejected_without_side_effects(string $path, mixed $value): void
    {
        $account = $this->account();
        $order = $this->order();
        data_set($order, $path, $value);
        Http::fake(['*' => Http::response($order)]);
        $this->preview($account)->assertOk()->assertDontSee('data-halsky-preview', false)->assertDontSee('Jan Kowalski');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public static function invalidOrders(): array
    {
        return [
            ['id', 'WRONG_ID'], ['organizationId', '497f6eca-6276-4993-bfeb-53cbbbba6f08'],
            ['customer.firstName', ['not a name']], ['delivery.address', 'not an address'],
            ['orderLines', []], ['orderLines.0.offer.product.name', null],
            ['paymentDetails.payments', false], ['createdAt', 'not a date'],
            ['finalPrice.amount', true], ['finalPrice.amount', '-0.01'], ['finalPrice.amount', '1.234'],
            ['finalPrice.amount', '999999999999999999999999999'], ['finalPrice.currency', '<script>'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_unsafe_ids_are_rejected_before_api_calls(string $id): void
    {
        $account = $this->account();
        $this->preview($account, $id)->assertSessionHasErrors('order_identifier');
        Http::assertNothingSent();
    }

    public static function invalidIds(): array
    {
        return [['../accept'], ['AB12345/refund'], ['https://evil.invalid'], ['..'], ["AB\n123"], [str_repeat('x', 256)]];
    }

    public function test_inactive_account_and_stale_form_never_read_an_order(): void
    {
        $account = $this->account();
        $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => 5, 'order_identifier' => 'AB12345'])
            ->assertSessionHasErrors('expected_lock_version');
        $account->forceFill(['is_active' => false])->save();
        $this->preview($account)->assertOk()->assertSee('Najpierw zapisz ustawienia');
        Http::assertNothingSent();
    }

    public function test_expired_token_is_renewed_only_at_official_token_endpoint(): void
    {
        $account = $this->account();
        $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'NEW_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 300]),
            '*inpsa/*' => Http::response($this->order()),
        ]);
        $this->preview($account)->assertOk()->assertSee('Jan Kowalski');
        $this->assertSame('NEW_TOKEN', $account->fresh()->access_token);
        Http::assertSentCount(2);
    }

    public function test_revoked_client_credentials_disable_connection_without_reading_an_order(): void
    {
        $account = $this->account();
        $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
        Http::fake(['*' => Http::response(['error' => 'SECRET_MUST_NOT_LEAK'], 401)]);
        $this->preview($account)->assertOk()->assertSee('odrzucił autoryzację')->assertDontSee('SECRET_MUST_NOT_LEAK');
        $this->assertFalse($account->fresh()->is_active);
        $this->assertNull($account->fresh()->access_token);
        Http::assertSentCount(1);
    }

    public function test_client_id_form_field_cannot_override_connection_or_inject_a_new_endpoint(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response($this->order())]);
        $this->post(route('integrations.von-halsky.preview'), [
            'expected_lock_version' => 0, 'order_identifier' => 'AB12345', 'organization_id' => 'OTHER_ORGANIZATION',
            'environment' => 'production', 'api_url' => 'https://evil.invalid', 'client_secret' => 'OTHER_SECRET',
        ])->assertOk()->assertSee('Jan Kowalski');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://stage-api.inpost-group.com/inpsa/v1/organizations/'.$account->organization_id.'/'));
        Http::assertSentCount(1);
    }

    public function test_missing_csrf_token_is_rejected_outside_the_test_bypass(): void
    {
        $this->app->instance('env', 'local');
        $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => 0, 'order_identifier' => 'AB12345'])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_unauthorized_read_is_retried_once_and_then_connection_is_disabled(): void
    {
        $account = $this->account();
        Http::fake([
            '*oauth2/token' => Http::response(['access_token' => 'NEW_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 300]),
            '*inpsa/*' => Http::response(['private' => 'MUST_NOT_LEAK'], 401),
        ]);
        $this->preview($account)->assertOk()->assertSee('odrzucił autoryzację')->assertDontSee('MUST_NOT_LEAK');
        $this->assertFalse($account->fresh()->is_active);
        $this->assertNull($account->fresh()->access_token);
        Http::assertSentCount(3);
    }

    public function test_rate_limit_honors_retry_after_without_automatic_retries(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $this->preview($account)->assertSee('Spróbuj za 120 s.');
        $this->preview($account)->assertSee('Limit zapytań');
        Http::assertSentCount(1);
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_network_failure_is_safe_and_does_not_retry_or_modify_orders(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::failedConnection()]);
        $this->preview($account)->assertOk()->assertSee('Nie udało się połączyć');
        $this->assertDatabaseCount('orders', 0);
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_disconnection_during_read_discards_customer_data(): void
    {
        $account = $this->account();
        Http::fake(function () use ($account) {
            app(VonHalskyAccountService::class)->disconnect($account->lock_version);

            return Http::response($this->order());
        });
        $this->preview($account)->assertOk()->assertSee('zmieniona')->assertDontSee('Jan Kowalski')->assertDontSee('data-halsky-preview', false);
        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_actions_use_web_csrf_protection_and_import_requires_confirmation(): void
    {
        foreach (['update', 'test-connection', 'preview', 'disconnect'] as $name) {
            $route = app('router')->getRoutes()->getByName('integrations.von-halsky.'.$name);
            $this->assertContains('web', $route->middleware());
            $this->assertNotContains('GET', $route->methods());
        }
        $this->get(route('integrations.von-halsky.preview'))->assertStatus(405);
        $this->post('/integrations/von-halsky/import')->assertSessionHasErrors(['order_identifier', 'preview_hash', 'quantities', 'confirmed_quantities']);
        Http::assertNothingSent();
    }

    public function test_customer_portal_host_cannot_access_connection_or_preview(): void
    {
        config(['app.url' => 'https://oms.example.test']);
        PortalSetting::query()->create(['singleton_key' => 'default', 'customer_domain' => 'klient.example.test']);
        $this->get('https://klient.example.test/integrations/von-halsky')->assertNotFound()->assertDontSee('Client Secret');
        $this->post('https://klient.example.test/integrations/von-halsky/preview', [
            'expected_lock_version' => 0, 'order_identifier' => 'AB12345',
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    private function preview(VonHalskyAccount $account, string $id = 'AB12345'): TestResponse
    {
        return $this->post(route('integrations.von-halsky.preview'), ['expected_lock_version' => $account->lock_version, 'order_identifier' => $id]);
    }

    private function account(): VonHalskyAccount
    {
        return VonHalskyAccount::query()->create([
            'name' => 'multi-click', 'organization_id' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67',
            'client_id' => 'TEST_CLIENT', 'client_secret' => 'TEST_SECRET', 'is_active' => true,
            'last_test_status' => 'success', 'access_token' => 'TEST_TOKEN', 'token_expires_at' => now()->addMinutes(5),
        ]);
    }

    private function order(): array
    {
        return [
            'id' => 'AB12345', 'organizationId' => 'd8ec11ce-12ba-4fb7-be9a-249e679f4e67', 'status' => 'ACCEPTED',
            'createdAt' => '2026-10-29T10:00:00Z', 'updatedAt' => '2026-10-29T10:01:00Z',
            'customer' => ['firstName' => 'Jan', 'lastName' => 'Kowalski', 'email' => 'jan@example.invalid', 'phoneNumber' => '+48500500500',
                'address' => ['street' => 'Lipowa', 'building' => '5', 'flat' => '2', 'postCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL']],
            'invoice' => ['companyName' => 'Firma testowa', 'taxIdPrefix' => 'PL', 'taxId' => '1234567890'],
            'delivery' => ['deliveryType' => 'APM', 'deliveryPoint' => 'WAW01M', 'price' => ['amount' => 10, 'currency' => 'PLN']],
            'orderLines' => [['offer' => ['product' => ['name' => 'Produkty testowe'], 'finalPrice' => ['amount' => 209.98, 'currency' => 'PLN']]]],
            'finalPrice' => ['amount' => 219.98, 'currency' => 'PLN'], 'basePrice' => ['amount' => 209.98, 'currency' => 'PLN'],
            'paymentDetails' => ['selectedPaymentType' => 'CARD', 'payments' => [['paymentId' => 'PAYMENT_TEST', 'paymentType' => 'CARD', 'paymentDate' => '2026-10-29T10:01:00Z']]],
            'comment' => 'Proszę o kontakt.',
        ];
    }
}
