<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPortalLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://oms.example.test']);
        Storage::fake('local');
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_customer_root_uses_the_existing_unavailable_page_and_security_headers(): void
    {
        $this->settings();
        $response = $this->get('https://klient.example.test/')->assertNotFound()
            ->assertViewIs('customer-portal.show')
            ->assertSeeText('Strona zamówienia jest niedostępna')
            ->assertSeeText('Link jest nieprawidłowy, wygasł lub został wyłączony. Skontaktuj się ze sprzedawcą.')
            ->assertHeaderMissing('Location')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertNull($response->viewData('data'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $invalid = $this->get('https://klient.example.test/1/AbCdEf1234')->assertNotFound()->assertViewIs('customer-portal.show');
        $this->assertNull($invalid->viewData('data'));
        $this->assertSame(
            preg_replace('/nonce="[^"]+"/', '', $response->getContent()),
            preg_replace('/nonce="[^"]+"/', '', $invalid->getContent())
        );
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_head_returns_404_without_a_body_or_redirect(): void
    {
        $this->settings();
        $this->head('https://klient.example.test/')->assertNotFound()->assertContent('')
            ->assertHeaderMissing('Location')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_landing_never_reads_order_data_or_creates_links_payments_or_settings(): void
    {
        $settings = $this->settings();
        $before = $settings->getAttributes();
        DB::enableQueryLog();
        $this->get('https://klient.example.test/')->assertNotFound();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(orders|order_items|invoices|customer_portal_links|paynow_payments)\b/i', $query['query']);
        }
        $this->assertSame($before, $settings->fresh()->getAttributes());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customer_portal_settings', 1);
        $this->assertDatabaseCount('customer_portal_links', 0);
        $this->assertDatabaseCount('paynow_payments', 0);
        $this->assertDatabaseCount('order_events', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_query_parameters_do_not_turn_the_root_into_order_access(): void
    {
        $this->settings();
        $order = $this->order();
        $url = app(PortalAccessService::class)->orderLink($order)['url'];
        $before = PortalLink::query()->sole()->getAttributes();
        $response = $this->get('https://klient.example.test/?orderNumber='.$order->id.'&token='.basename($url))
            ->assertNotFound()->assertViewIs('customer-portal.show')->assertDontSee('Private Buyer');
        $this->assertNull($response->viewData('data'));
        $this->assertSame($before, PortalLink::query()->sole()->getAttributes());
        $this->get($url)->assertOk();
    }

    public function test_owner_root_keeps_the_dashboard(): void
    {
        $this->settings();
        $this->order();
        $this->get('https://oms.example.test/')->assertOk()->assertViewIs('dashboard')
            ->assertViewHas('dashboardStats', fn ($stats) => $stats['newOrders'] === 1);
    }

    public function test_local_loopback_root_keeps_the_dashboard_with_a_customer_domain(): void
    {
        $this->settings();
        $this->app->detectEnvironment(fn () => 'local');
        $this->get('http://127.0.0.1:8000/')->assertOk()->assertViewIs('dashboard');
    }

    #[DataProvider('adminPaths')]
    public function test_customer_host_still_blocks_admin_and_unknown_paths(string $path): void
    {
        $this->settings();
        $this->get('https://klient.example.test'.$path)->assertNotFound()->assertHeaderMissing('Location');
    }

    public static function adminPaths(): array
    {
        return [['/orders'], ['/settings/customer-portal'], ['/invoices'], ['/integrations'],
            ['/integrations/paynow'], ['/index.php'], ['/unknown']];
    }

    #[DataProvider('otherRoots')]
    public function test_other_hosts_or_noncanonical_origins_do_not_get_the_landing_or_dashboard(string $url): void
    {
        $this->settings();
        $this->get($url)->assertNotFound()->assertContent('');
    }

    public static function otherRoots(): array
    {
        return [['https://unknown.example.test/'], ['http://klient.example.test/'], ['https://klient.example.test:444/']];
    }

    public function test_cleared_or_changed_customer_domain_never_exposes_the_dashboard_on_the_old_host(): void
    {
        $settings = $this->settings();
        $settings->update(['customer_domain' => 'new-client.example.test']);
        $this->get('https://klient.example.test/')->assertNotFound()->assertContent('');
        $this->get('https://new-client.example.test/')->assertNotFound()->assertViewIs('customer-portal.show');
        $settings->update(['customer_domain' => null]);
        $this->get('https://new-client.example.test/')->assertNotFound()->assertContent('');
        $this->get('https://oms.example.test/')->assertOk()->assertViewIs('dashboard');
    }

    public function test_missing_configuration_or_migration_fails_closed_without_creating_settings(): void
    {
        $this->get('https://klient.example.test/')->assertNotFound()->assertContent('');
        $this->assertDatabaseCount('customer_portal_settings', 0);
        $this->get('https://oms.example.test/')->assertOk()->assertViewIs('dashboard');
        Schema::table('customer_portal_settings', fn ($table) => $table->dropColumn('customer_domain'));
        $this->get('https://klient.example.test/')->assertNotFound()->assertContent('');
        $this->assertDatabaseCount('customer_portal_settings', 0);
    }

    public function test_disabled_portal_still_shows_only_the_unavailable_page_at_the_root(): void
    {
        $this->settings()->update(['is_enabled' => false]);
        $this->get('https://klient.example.test/')->assertNotFound()->assertViewIs('customer-portal.show');
    }

    public function test_landing_uses_the_separate_secure_host_only_customer_session(): void
    {
        $this->settings();
        config(['session.domain' => '.example.test']);
        $response = $this->get('https://klient.example.test/')->assertNotFound();
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'nex_oms_customer_portal_session');
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
    }

    #[DataProvider('unsafeMethods')]
    public function test_root_does_not_accept_write_methods(string $method): void
    {
        $this->settings();
        $this->call($method, 'https://klient.example.test/')->assertStatus(405);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customer_portal_links', 0);
        $this->assertDatabaseCount('paynow_payments', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function unsafeMethods(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    private function settings(): PortalSetting
    {
        return PortalSetting::query()->create(['customer_domain' => 'klient.example.test', 'is_enabled' => true])->fresh();
    }

    private function order(): Order
    {
        return Order::query()->create(['source' => 'manual', 'status' => Order::STATUS_NEW,
            'billing_name' => 'Private Buyer', 'customer_email' => 'buyer@example.test',
            'currency' => 'PLN', 'total_gross' => '100.00', 'paid_amount' => '0.00', 'purchased_at' => now()]);
    }
}
