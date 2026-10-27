<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCustomerPortalRoutes;
use Tests\TestCase;

class CustomerPortalAutomaticLinksTest extends TestCase
{
    use BuildsCustomerPortalRoutes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    #[DataProvider('sources')]
    public function test_new_order_gets_an_active_encrypted_link_without_enabling_payments(string $source): void
    {
        $order = $this->order(['source' => $source]);
        $link = PortalLink::query()->sole();
        $settings = PortalSetting::query()->sole();
        $this->assertTrue($settings->is_enabled);
        $this->assertFalse($settings->paynow_enabled);
        $this->assertFalse($settings->paynow_retry_enabled);
        $this->assertSame($order->id, $link->order_id);
        $this->assertNotSame($link->token, DB::table('customer_portal_links')->value('token'));
        $data = app(PortalAccessService::class)->orderLink($order);
        $this->assertTrue($data['active']);
        $this->get($data['url'])->assertOk();
        $this->assertDatabaseCount('paynow_payments', 0);
        $this->assertDatabaseCount('order_email_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function sources(): array
    {
        return [['manual'], ['allegro'], ['prestashop'], ['custom-source']];
    }

    public function test_link_is_bound_to_final_order_data_only_after_creation_transaction_commits(): void
    {
        $order = DB::transaction(function (): Order {
            $order = $this->order(['billing_name' => null]);
            $this->assertDatabaseCount('customer_portal_links', 0);
            $order->update(['billing_name' => 'Final buyer']);

            return $order;
        });
        $data = app(PortalAccessService::class)->orderLink($order->fresh());
        $this->assertTrue($data['active']);
        $this->assertDatabaseCount('customer_portal_links', 1);
        $this->get($data['url'])->assertOk();
    }

    public function test_rolled_back_order_does_not_leave_a_link_or_enable_the_portal(): void
    {
        try {
            DB::transaction(function (): void {
                $this->order();
                throw new \RuntimeException('Rollback fixture');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('customer_portal_settings', 0);
            $this->assertDatabaseCount('customer_portal_links', 0);
        }
    }

    public function test_status_amount_and_repeated_provisioning_do_not_change_the_link(): void
    {
        $order = $this->order();
        $link = PortalLink::query()->sole();
        $before = $link->getAttributes();
        $order->update(['status' => Order::STATUS_SHIPPED, 'paid_amount' => '50.00', 'total_gross' => '150.00']);
        app(PortalAccessService::class)->provision($order->id);
        app(PortalAccessService::class)->provision($order->id);
        $this->assertSame($before, $link->fresh()->getAttributes());
        $this->assertTrue(app(PortalAccessService::class)->orderLink($order->fresh())['active']);
    }

    public function test_global_disable_remains_disabled_and_never_changes_existing_payment_configuration(): void
    {
        $settings = PortalSetting::query()->create(['is_enabled' => false, 'paynow_enabled' => true,
            'paynow_retry_enabled' => true, 'paynow_sources' => ['prestashop']]);
        $before = $settings->fresh()->getAttributes();
        $order = $this->order();
        $link = app(PortalAccessService::class)->orderLink($order);
        $this->assertSame($before, $settings->fresh()->getAttributes());
        $this->assertFalse($link['active']);
        $this->get($link['url'])->assertNotFound();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1])->assertUnprocessable();
    }

    #[DataProvider('inactiveLinks')]
    public function test_automatic_provision_and_backfill_never_revive_an_inactive_link(string $scenario): void
    {
        $order = $this->order();
        $link = PortalLink::query()->sole();
        match ($scenario) {
            'revoked' => app(PortalAccessService::class)->revoke($order, 1),
            'expired' => $link->update(['expires_at' => now()->subSecond()]),
            'buyer' => $order->update(['customer_email' => 'another@example.test']),
        };
        $before = $link->fresh()->getAttributes();
        app(PortalAccessService::class)->provision($order->id);
        $this->artisan('customer-portal:prepare-links')->assertSuccessful();
        $this->assertSame($before, $link->fresh()->getAttributes());
        $this->assertFalse(app(PortalAccessService::class)->orderLink($order->fresh())['active']);
        $this->get($this->portalRoute('customer-portal.show', ['token' => $link->token]))->assertNotFound();
    }

    public static function inactiveLinks(): array
    {
        return [['revoked'], ['expired'], ['buyer']];
    }

    public function test_copying_an_order_creates_a_different_capability(): void
    {
        $order = $this->order();
        $copy = $order->replicate();
        $copy->save();
        $links = PortalLink::query()->get();
        $this->assertCount(2, $links);
        $this->assertNotSame($links[0]->token, $links[1]->token);
    }

    public function test_backfill_dry_run_is_read_only_even_with_enable_option(): void
    {
        Order::withoutEvents(fn () => $this->order());
        $this->artisan('customer-portal:prepare-links', ['--dry-run' => true, '--enable' => true])
            ->expectsOutput('Brakujace linki: 1. Bez zmian.')->assertSuccessful();
        $this->assertDatabaseCount('customer_portal_settings', 0);
        $this->assertDatabaseCount('customer_portal_links', 0);
    }

    public function test_backfill_covers_legacy_orders_without_renewing_expiry_or_processing_trash(): void
    {
        $settings = PortalSetting::query()->create(['is_enabled' => false]);
        $recent = Order::withoutEvents(fn () => $this->order());
        $old = Order::withoutEvents(fn () => $this->order(['purchased_at' => now()->subDays(100)]));
        $trash = Order::withoutEvents(fn () => $this->order());
        $trash->delete();
        $this->artisan('customer-portal:prepare-links')->assertSuccessful();
        $this->assertDatabaseCount('customer_portal_links', 2);
        $this->assertFalse($settings->fresh()->is_enabled);
        $this->assertTrue(PortalLink::query()->where('order_id', $old->id)->sole()->expires_at->isPast());
        $this->assertTrue(PortalLink::query()->where('order_id', $recent->id)->exists());
        $this->assertFalse(PortalLink::query()->where('order_id', $trash->id)->exists());
    }

    public function test_explicit_enable_is_idempotent_and_preserves_paynow_settings(): void
    {
        $settings = PortalSetting::query()->create(['is_enabled' => false, 'paynow_enabled' => true,
            'paynow_retry_enabled' => false, 'paynow_sources' => []]);
        Order::withoutEvents(fn () => $this->order());
        $this->artisan('customer-portal:prepare-links', ['--enable' => true])->assertSuccessful();
        $this->artisan('customer-portal:prepare-links', ['--enable' => true])->assertSuccessful();
        $this->assertTrue($settings->fresh()->is_enabled);
        $this->assertSame(2, $settings->fresh()->revision);
        $this->assertTrue($settings->fresh()->paynow_enabled);
        $this->assertFalse($settings->fresh()->paynow_retry_enabled);
        $this->assertSame([], $settings->fresh()->paynow_sources);
        $this->assertDatabaseCount('customer_portal_links', 1);
    }

    public function test_missing_portal_migration_does_not_break_order_creation(): void
    {
        Schema::drop('customer_portal_links');
        $order = $this->order();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseCount('customer_portal_settings', 0);
        $this->artisan('customer-portal:prepare-links')->assertFailed();
    }

    public function test_a_failed_provision_does_not_fail_committed_order_or_log_the_secret(): void
    {
        $this->mock(PortalAccessService::class)->shouldReceive('provision')->once()->andThrow(new \RuntimeException('SECRET URL'));
        Log::shouldReceive('error')->once()->with('Customer portal link provisioning failed.', \Mockery::on(fn ($context) => $context['exception_class'] === \RuntimeException::class && ! str_contains(json_encode($context), 'SECRET')));
        $order = $this->order();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_order_view_only_shows_the_ready_link_at_the_bottom_of_management_and_get_is_read_only(): void
    {
        $order = $this->order();
        $link = PortalLink::query()->sole();
        $before = $link->getAttributes();
        $response = $this->get(route('orders.show', $order))->assertOk()
            ->assertDontSee('data-customer-portal-actions', false)->assertDontSee('data-portal-link-form', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//*[@data-customer-portal-link]');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Strona z informacjami o zamówieniu:', $rows->item(0)->textContent);
        $this->assertSame($this->portalRoute('customer-portal.show', ['token' => $link->token]), $xpath->query('.//a', $rows->item(0))->item(0)->getAttribute('href'));
        $this->assertCount(1, $xpath->query('ancestor::*[contains(concat(" ",normalize-space(@class)," ")," nex-card ")][.//h2[contains(.,"Zarządzanie")]]', $rows->item(0)));
        $this->assertCount(0, $xpath->query('//button[normalize-space(.)="Strona klienta"]'));
        $this->assertSame($before, $link->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_settings_can_revoke_and_explicitly_renew_a_link_without_changing_payments(): void
    {
        $order = $this->order();
        $old = app(PortalAccessService::class)->orderLink($order)['url'];
        $this->get(route('customer-portal.settings', ['order' => $order->id]))->assertOk()->assertSee('Wygeneruj nowy link')->assertSee($old, false);
        $this->post(route('customer-portal.revoke', $order), ['confirm' => 1, 'expected_generation' => 1])
            ->assertRedirect(route('customer-portal.settings', ['order' => $order->id]));
        $this->get($old)->assertNotFound();
        $this->post(route('customer-portal.issue', $order), ['confirm' => 1, 'rotate' => 1, 'expected_generation' => 1])
            ->assertRedirect(route('customer-portal.settings', ['order' => $order->id]));
        $new = app(PortalAccessService::class)->orderLink($order)['url'];
        $this->assertNotSame($old, $new);
        $this->get($new)->assertOk();
        $this->get($old)->assertNotFound();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'rotate' => 1, 'expected_generation' => 1])->assertUnprocessable();
        $this->assertFalse(PortalSetting::query()->sole()->paynow_enabled);
        $this->assertFalse(PortalSetting::query()->sole()->paynow_retry_enabled);
    }

    public function test_get_of_legacy_order_does_not_generate_a_missing_link(): void
    {
        $order = Order::withoutEvents(fn () => $this->order());
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Link nie został jeszcze przygotowany.');
        $this->get(route('customer-portal.preview', $order))->assertOk();
        $this->assertDatabaseCount('customer_portal_links', 0);
        $this->assertDatabaseCount('customer_portal_settings', 0);
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create($attributes + ['source' => 'manual', 'status' => Order::STATUS_NEW,
            'billing_name' => 'Buyer', 'customer_email' => 'customer@example.test', 'currency' => 'PLN',
            'total_gross' => '100.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid', 'purchased_at' => now()]);
    }
}
