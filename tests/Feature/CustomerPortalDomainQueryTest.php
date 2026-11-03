<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalUrlService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerPortalDomainQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://oms.example.test']);
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
        Route::middleware('web')->get('/_test/portal-domain', function (Request $request, PortalUrlService $urls) {
            $before = count(DB::getQueryLog());
            $domain = $urls->domain();
            $base = $urls->base();
            $urls->accepts($request);
            $urls->isCustomerLanding($request);
            $otherInstanceDomain = (new PortalUrlService)->domain();

            return response()->json([
                'domain' => $domain,
                'base' => $base,
                'other_instance_domain' => $otherInstanceDomain,
                'additional_queries' => count(DB::getQueryLog()) - $before,
            ]);
        });
    }

    #[DataProvider('domains')]
    public function test_domain_is_read_once_per_request_including_absent_values(?string $domain, bool $record): void
    {
        if ($record) {
            PortalSetting::query()->create(['customer_domain' => $domain]);
        }
        $before = PortalSetting::query()->get()->map->getAttributes()->all();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(PortalUrlService::class)->domain();
            $singleReadCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->get('https://oms.example.test/_test/portal-domain')->assertOk()
                ->assertJsonPath('domain', $domain ? strtolower($domain) : null)
                ->assertJsonPath('base', $domain ? 'https://'.strtolower($domain) : 'https://oms.example.test')
                ->assertJsonPath('other_instance_domain', $domain ? strtolower($domain) : null)
                ->assertJsonPath('additional_queries', 0);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertGreaterThan(0, $singleReadCount);
        $this->assertCount($singleReadCount, $queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        $this->assertFalse(app('request')->attributes->has(PortalUrlService::REQUEST_DOMAIN_ATTRIBUTE));
        $this->assertSame($before, PortalSetting::query()->get()->map->getAttributes()->all());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function domains(): array
    {
        return [
            'configured' => ['Klient.Example.Test', true],
            'null' => [null, true],
            'empty' => ['', true],
            'missing record' => [null, false],
        ];
    }

    public function test_retained_service_reads_changed_domain_on_each_request_and_outside_http(): void
    {
        $settings = PortalSetting::query()->create(['customer_domain' => null]);
        $urls = new PortalUrlService;
        $this->app->instance(PortalUrlService::class, $urls);

        foreach ([null, 'first.example.test', 'second.example.test', null] as $domain) {
            $settings->update(['customer_domain' => $domain]);
            $this->get('https://oms.example.test/_test/portal-domain')->assertOk()->assertJsonPath('domain', $domain);
            $this->assertFalse(app('request')->attributes->has(PortalUrlService::REQUEST_DOMAIN_ATTRIBUTE));
        }
        $request = app('request');
        foreach (['queue-first.example.test', 'queue-second.example.test', null] as $domain) {
            $settings->update(['customer_domain' => $domain]);
            $this->assertSame($domain, $urls->domain());
            $this->assertSame($request, app('request'));
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_invalid_domain_still_blocks_http_and_is_not_remembered_after_repair(): void
    {
        $settings = PortalSetting::query()->create(['customer_domain' => 'https://invalid.example.test/path']);
        $this->get('https://oms.example.test/_test/portal-domain')->assertStatus(503);
        $this->assertFalse(app('request')->attributes->has(PortalUrlService::REQUEST_DOMAIN_ATTRIBUTE));
        $settings->update(['customer_domain' => 'klient.example.test']);
        $this->get('https://oms.example.test/_test/portal-domain')->assertOk()->assertJsonPath('domain', 'klient.example.test');
    }

    public function test_domain_value_is_forgotten_even_when_the_controller_throws(): void
    {
        $settings = PortalSetting::query()->create(['customer_domain' => 'klient.example.test']);
        Route::middleware('web')->get('/_test/portal-domain-error', fn () => throw new RuntimeException('Test controller error.'));
        $this->withoutExceptionHandling();
        try {
            $this->get('https://oms.example.test/_test/portal-domain-error');
            $this->fail('The controller must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test controller error.', $exception->getMessage());
        }
        $this->assertFalse(app('request')->attributes->has(PortalUrlService::REQUEST_DOMAIN_ATTRIBUTE));
        $settings->update(['customer_domain' => 'updated.example.test']);
        $this->assertSame('updated.example.test', app(PortalUrlService::class)->domain());
    }

    #[DataProvider('missingSchema')]
    public function test_missing_schema_is_read_once_without_creating_settings(bool $missingTable): void
    {
        if ($missingTable) {
            Schema::drop('customer_portal_settings');
        } else {
            Schema::table('customer_portal_settings', fn ($table) => $table->dropColumn('customer_domain'));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(PortalUrlService::class)->domain();
            $singleReadCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->get('https://oms.example.test/_test/portal-domain')->assertOk()
                ->assertJsonPath('domain', null)->assertJsonPath('additional_queries', 0);
            $this->assertCount($singleReadCount, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        if (! $missingTable) {
            $this->assertDatabaseCount('customer_portal_settings', 0);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function missingSchema(): array
    {
        return ['table' => [true], 'column' => [false]];
    }
}
