<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\CustomerPortal\Models\PortalSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPortalInformationTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = 'https://klient.multi-click.pl';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://oms.example.test']);
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_information_is_public_without_an_order_or_session_even_when_the_portal_is_disabled(): void
    {
        PortalSetting::query()->create(['customer_domain' => 'klient.multi-click.pl', 'is_enabled' => false]);
        config(['session.driver' => 'database']);
        DB::enableQueryLog();
        try {
            $this->get(self::CUSTOMER.'/informacje?orderNumber=123&token=PrivateRef')
                ->assertOk()->assertViewIs('customer-portal.information')
                ->assertSeeText('Informacje dla klientów')
                ->assertSeeText('Multi-Click Eliasz Malczyk')
                ->assertSeeText('kontakt@multi-click.pl')
                ->assertSeeText('6282192260')
                ->assertSee('https://multi-click.pl/content/regulamin')
                ->assertSee('https://multi-click.pl/content/polityka-prywatnosci')
                ->assertSee('https://multi-click.pl/content/dostawa')
                ->assertDontSee('PrivateRef')->assertHeaderMissing('Set-Cookie')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Frame-Options', 'DENY');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(orders|customer_portal_links|paynow_payments|sessions)\b/i', $query['query']);
        }
        $this->head(self::CUSTOMER.'/informacje')->assertOk()->assertContent('')->assertHeaderMissing('Set-Cookie');
        $this->post(self::CUSTOMER.'/informacje')->assertStatus(405);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('otherOrigins')]
    public function test_information_is_not_served_on_admin_unknown_or_noncanonical_origins(string $origin): void
    {
        PortalSetting::query()->create(['customer_domain' => 'klient.multi-click.pl']);
        $this->get($origin.'/informacje')->assertNotFound()->assertDontSee('kontakt@multi-click.pl');
    }

    public static function otherOrigins(): array
    {
        return [['https://oms.example.test'], ['https://other.example.test'], ['http://klient.multi-click.pl'], ['https://klient.multi-click.pl:8443']];
    }

    public function test_information_is_not_served_if_the_seller_profile_is_for_another_domain(): void
    {
        PortalSetting::query()->create(['customer_domain' => 'other.example.test']);
        $this->get('https://other.example.test/informacje')->assertNotFound()->assertDontSee('kontakt@multi-click.pl');
    }

    public function test_existing_customer_pages_link_to_documents_without_the_auxiliary_cookie(): void
    {
        PortalSetting::query()->create(['customer_domain' => 'klient.multi-click.pl']);
        foreach (['/' => 404, '/123/AbCdEf1234' => 404, '/paynow/powrot' => 200] as $path => $status) {
            $this->get(self::CUSTOMER.$path)->assertStatus($status)
                ->assertSee(self::CUSTOMER.'/informacje')
                ->assertSee('https://multi-click.pl/content/regulamin')
                ->assertSee('https://multi-click.pl/content/polityka-prywatnosci')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertCookie('nex_oms_customer_portal_session')->assertCookieMissing('XSRF-TOKEN');
        }

        $this->get('https://oms.example.test/')->assertOk()->assertCookie('XSRF-TOKEN');
        Http::assertNothingSent();
    }

    public function test_local_information_preview_does_not_use_the_configured_customer_host(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->get('http://127.0.0.1:8000/informacje')->assertOk()->assertHeaderMissing('Set-Cookie');
    }
}
