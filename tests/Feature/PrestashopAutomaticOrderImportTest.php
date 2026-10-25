<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Integrations\Prestashop\Enums\PrestashopCatalogVatMode;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Jobs\DiscoverPrestashopOrdersJob;
use Modules\Integrations\Prestashop\Jobs\ImportPrestashopOrderJob;
use Modules\Integrations\Prestashop\Jobs\SyncPrestashopOrderStatusJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderImportAttempt;
use Modules\Integrations\Prestashop\Services\PrestashopAutomaticOrderImportService;
use Modules\Integrations\Prestashop\Services\PrestashopOrderDiscoveryService;
use Modules\Integrations\Prestashop\Services\PrestashopOrderImportDispatcher;
use Modules\Integrations\Prestashop\Services\PrestashopOrderImportService;
use Tests\TestCase;

class PrestashopAutomaticOrderImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_enabling_import_starts_from_now_without_contacting_the_store(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:00:15');
        Http::fake();
        Queue::fake();
        $account = $this->account(['order_import_enabled' => false]);

        $this->put(route('integrations.prestashop.order-import.update'), [
            'order_import_enabled' => '1',
        ])->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'orders']));

        $account->refresh();

        $this->assertTrue($account->order_import_enabled);
        $this->assertSame('2026-10-14 10:00:15', $account->order_import_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 10:00:15', $account->last_order_import_scan_at?->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_import_cannot_be_enabled_for_an_inactive_integration(): void
    {
        $account = $this->account([
            'is_active' => false,
            'order_import_enabled' => false,
        ]);

        $this->from(route('integrations.prestashop.edit', ['tab' => 'orders']))
            ->put(route('integrations.prestashop.order-import.update'), [
                'order_import_enabled' => '1',
            ])
            ->assertSessionHasErrors('order_import_enabled');

        $this->assertFalse($account->fresh()->order_import_enabled);
    }

    public function test_product_import_preferences_are_saved_and_validated_without_network_calls(): void
    {
        Http::fake();
        $account = $this->account(['order_import_enabled' => true]);

        $this->put(route('integrations.prestashop.order-import.update'), [
            'order_import_enabled' => '1',
            'use_linked_products' => '1',
            'use_catalog_product_names' => '0',
            'catalog_vat_mode' => PrestashopCatalogVatMode::Catalog->value,
        ])->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'orders']));

        $account->refresh();

        $this->assertTrue($account->use_linked_products);
        $this->assertFalse($account->use_catalog_product_names);
        $this->assertSame(PrestashopCatalogVatMode::Catalog, $account->catalog_vat_mode);
        Http::assertNothingSent();

        $this->from(route('integrations.prestashop.edit', ['tab' => 'orders']))
            ->put(route('integrations.prestashop.order-import.update'), [
                'order_import_enabled' => '1',
                'use_linked_products' => '0',
                'use_catalog_product_names' => '1',
                'catalog_vat_mode' => 'unsupported',
            ])
            ->assertSessionHasErrors('catalog_vat_mode');

        $account->refresh();

        $this->assertTrue($account->use_linked_products);
        $this->assertFalse($account->use_catalog_product_names);
        $this->assertSame(PrestashopCatalogVatMode::Catalog, $account->catalog_vat_mode);
    }

    public function test_default_vat_setting_can_be_saved_cleared_and_validated_without_network_calls(): void
    {
        Http::fake();
        $account = $this->account();
        $credential = $account->getRawOriginal('api_key');
        $this->assertNull($account->default_vat_rate);
        $this->put(route('integrations.prestashop.order-import.update'), [
            'order_import_enabled' => '0', 'default_vat_rate' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $account->fresh()->default_vat_rate);
        $this->put(route('integrations.prestashop.order-import.update'), [
            'order_import_enabled' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $account->fresh()->default_vat_rate);
        foreach (['-1', '101', 'zw', '2.5', true, ['23']] as $invalid) {
            $this->putJson(route('integrations.prestashop.order-import.update'), [
                'order_import_enabled' => '0', 'default_vat_rate' => $invalid,
            ])->assertUnprocessable()->assertJsonValidationErrors('default_vat_rate');
        }
        $this->put(route('integrations.prestashop.order-import.update'), [
            'order_import_enabled' => '0', 'default_vat_rate' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($account->fresh()->default_vat_rate);
        $this->assertSame($credential, $account->fresh()->getRawOriginal('api_key'));
        $this->get(route('integrations.prestashop.edit', ['tab' => 'orders']))->assertOk()->assertSee('Domyślna stawka VAT');
        Http::assertNothingSent();
    }

    public function test_scheduler_dispatches_discovery_only_for_enabled_import(): void
    {
        Queue::fake();
        $account = $this->account(['order_import_enabled' => false]);

        $this->assertFalse(app(PrestashopOrderImportDispatcher::class)->dispatchDue());
        Queue::assertNothingPushed();

        $account->forceFill(['order_import_enabled' => true])->save();

        $this->assertTrue(app(PrestashopOrderImportDispatcher::class)->dispatchDue());
        Queue::assertPushed(
            DiscoverPrestashopOrdersJob::class,
            fn (DiscoverPrestashopOrdersJob $job): bool => $job->accountId === $account->id
                && $job->queue === 'integrations',
        );
    }

    public function test_discovery_uses_creation_date_and_queues_new_order_after_safety_delay(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:05:30');
        Queue::fake();
        $account = $this->account([
            'order_import_enabled' => true,
            'order_import_started_at' => '2026-10-14 10:00:00',
            'last_order_import_scan_at' => '2026-10-14 10:01:00',
        ]);
        Http::fake([
            'https://multi-click.pl/api/orders*' => Http::response([
                'orders' => [[
                    'id' => 13590,
                    'reference' => 'PS13590',
                    'date_add' => '2026-10-14 10:03:00',
                ]],
            ]),
        ]);

        $result = app(PrestashopOrderDiscoveryService::class)->discover($account->id);

        $this->assertSame(['discovered' => 1, 'queued' => 1], $result);
        $this->assertDatabaseHas('prestashop_order_import_attempts', [
            'prestashop_account_id' => $account->id,
            'external_order_id' => 13590,
            'external_reference' => 'PS13590',
            'status' => PrestashopOrderImportAttempt::STATUS_PENDING,
        ]);
        $this->assertSame(
            '2026-10-14 10:04:30',
            $account->fresh()->last_order_import_scan_at?->format('Y-m-d H:i:s'),
        );
        Queue::assertPushed(
            ImportPrestashopOrderJob::class,
            fn (ImportPrestashopOrderJob $job): bool => $job->queue === 'integrations',
        );
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['date'] ?? null) === '1'
                && ($query['display'] ?? null) === '[id,reference,date_add]'
                && ($query['filter']['date_add'] ?? null) === '[2026-10-14 10:00:00,2026-10-14 10:04:30]';
        });
    }

    public function test_pending_attempt_is_queued_even_when_no_new_time_range_is_ready(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:05:30');
        Queue::fake();
        Http::fake();
        $account = $this->account([
            'order_import_enabled' => true,
            'order_import_started_at' => '2026-10-14 10:05:00',
            'last_order_import_scan_at' => '2026-10-14 10:05:00',
        ]);
        $attempt = $this->attempt($account);

        $result = app(PrestashopOrderDiscoveryService::class)->discover($account->id);

        $this->assertSame(['discovered' => 0, 'queued' => 1], $result);
        Queue::assertPushed(
            ImportPrestashopOrderJob::class,
            fn (ImportPrestashopOrderJob $job): bool => $job->attemptId === $attempt->id,
        );
        Http::assertNothingSent();
    }

    public function test_automatic_import_applies_stored_status_mapping_without_outbound_loop(): void
    {
        Queue::fake();
        OrderStatusSetting::syncDefaults();
        $account = $this->account(['order_import_enabled' => true]);
        $account->orderStates()->create([
            'external_id' => 4,
            'name' => 'Wysłane',
            'shipped' => true,
            'is_available' => true,
            'refreshed_at' => now(),
        ]);
        $shipped = OrderStatusSetting::query()->where('status', Order::STATUS_SHIPPED)->firstOrFail();
        $account->inboundStatusMappings()->create([
            'external_order_state_id' => 4,
            'order_status_setting_id' => $shipped->id,
        ]);
        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => 'PS13581',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'payment_status' => 'unpaid',
        ]);
        $account->orderLinks()->create([
            'order_id' => $order->id,
            'external_order_id' => 13581,
            'external_reference' => 'PS13581',
            'external_order_state_id' => 4,
            'external_order_state_name' => 'Wysłane',
            'source_hash' => hash('sha256', 'prestashop-order-13581'),
            'imported_at' => now(),
        ]);
        $attempt = $this->attempt($account);
        $importer = Mockery::mock(PrestashopOrderImportService::class);
        $importer->shouldReceive('importForAccount')
            ->once()
            ->withArgs(fn (PrestashopAccount $passedAccount, string $identifier): bool => $passedAccount->is($account)
                && $identifier === '13581')
            ->andReturn($order);
        $this->app->instance(PrestashopOrderImportService::class, $importer);

        app(PrestashopAutomaticOrderImportService::class)->importAttempt($attempt->id);

        $attempt->refresh();
        $this->assertSame(PrestashopOrderImportAttempt::STATUS_IMPORTED, $attempt->status);
        $this->assertSame($order->id, $attempt->order_id);
        $this->assertSame(1, $attempt->attempts);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        Queue::assertPushed(
            EvaluateAutomationEventJob::class,
            fn (EvaluateAutomationEventJob $job): bool => $job->eventName === 'order.status_changed'
                && ($job->eventPayload['source'] ?? null) === 'prestashop_order_import',
        );
        Queue::assertNotPushed(SyncPrestashopOrderStatusJob::class);
    }

    public function test_failed_attempt_can_be_retried_but_imported_attempt_is_not_dispatched_again(): void
    {
        Queue::fake();
        $account = $this->account(['order_import_enabled' => true]);
        $attempt = $this->attempt($account, [
            'status' => PrestashopOrderImportAttempt::STATUS_FAILED,
            'last_error' => 'Błąd testowy.',
        ]);

        $this->post(route('integrations.prestashop.order-import.retry', $attempt))
            ->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'orders']))
            ->assertSessionHas('success');

        $this->assertSame(PrestashopOrderImportAttempt::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->last_error);
        Queue::assertPushed(ImportPrestashopOrderJob::class, 1);

        Queue::fake();
        $attempt->forceFill(['status' => PrestashopOrderImportAttempt::STATUS_IMPORTED])->save();

        $this->post(route('integrations.prestashop.order-import.retry', $attempt))
            ->assertSessionHas('success', 'To zamówienie zostało już zaimportowane.');

        Queue::assertNotPushed(ImportPrestashopOrderJob::class);
    }

    public function test_permanent_discovery_error_is_recorded_without_advancing_cursor(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 10:05:30');
        $account = $this->account([
            'order_import_enabled' => true,
            'order_import_started_at' => '2026-10-14 10:00:00',
            'last_order_import_scan_at' => '2026-10-14 10:01:00',
        ]);
        Http::fake([
            'https://multi-click.pl/api/orders*' => Http::response([], 403),
        ]);
        $job = new DiscoverPrestashopOrdersJob($account->id);

        $job->handle(app(PrestashopOrderDiscoveryService::class));

        $account->refresh();
        $this->assertSame('2026-10-14 10:01:00', $account->last_order_import_scan_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($account->last_order_import_error_at);
        $this->assertSame('Brak uprawnienia odczytu zasobu PrestaShop: orders.', $account->last_order_import_error);
    }

    public function test_orders_tab_shows_import_settings_diagnostics_and_failed_attempt(): void
    {
        $account = $this->account([
            'order_import_enabled' => true,
            'order_import_started_at' => now()->subMinutes(5),
            'last_order_import_scan_at' => now()->subMinutes(2),
            'last_order_import_error_at' => now()->subMinute(),
            'last_order_import_error' => 'Błąd testowy pobierania.',
        ]);
        $this->attempt($account, [
            'status' => PrestashopOrderImportAttempt::STATUS_FAILED,
            'last_error' => 'Błąd testowy importu.',
        ]);

        $response = $this
            ->withSession(['success' => 'Sprawdzanie nowych zamówień PrestaShop zostało zlecone.'])
            ->get(route('integrations.prestashop.edit', ['tab' => 'orders']));

        $response
            ->assertOk()
            ->assertSeeText('Automatyczne pobieranie zamówień')
            ->assertSeeText('Produkty w importowanych zamówieniach')
            ->assertSeeText('Podmieniaj produkty na ręcznie powiązane produkty OMS')
            ->assertSeeText('Używaj nazw produktów z katalogu OMS')
            ->assertSeeText('Stawki VAT z katalogu OMS')
            ->assertSeeText('Cena, ilość i wartość pozycji zawsze pozostają zgodne z zamówieniem PrestaShop.')
            ->assertSeeText('Sprawdź nowe zamówienia')
            ->assertDontSeeText('Sprawdź nowe zamówienia teraz')
            ->assertSeeText('Ostatnie próby importu')
            ->assertSeeText('Błąd testowy pobierania.')
            ->assertSeeText('Błąd testowy importu.')
            ->assertSeeText('Ponów')
            ->assertSee('data-prestashop-success-alert', false)
            ->assertSee('}, 5000);', false);

        $this->assertSame(
            1,
            substr_count(
                $response->getContent(),
                'Sprawdzanie nowych zamówień PrestaShop zostało zlecone.',
            ),
        );
    }

    private function account(array $overrides = []): PrestashopAccount
    {
        return PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
            'order_import_enabled' => true,
            'last_tested_at' => now(),
            'last_test_status' => PrestashopConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ], $overrides));
    }

    private function attempt(
        PrestashopAccount $account,
        array $overrides = [],
    ): PrestashopOrderImportAttempt {
        return $account->orderImportAttempts()->create(array_merge([
            'external_order_id' => 13581,
            'external_reference' => 'PS13581',
            'status' => PrestashopOrderImportAttempt::STATUS_PENDING,
            'attempts' => 0,
            'discovered_at' => now(),
            'external_created_at' => now()->subMinute(),
        ], $overrides));
    }
}
