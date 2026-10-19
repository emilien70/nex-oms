<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Shipments\Models\CourierAccount;
use Tests\TestCase;

class OrderIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_use_id_internally_and_external_id_for_integrations(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'id'));
        $this->assertTrue(Schema::hasColumn('orders', 'external_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'order_number'));

        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => 'PRESTA-66',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
        ]);

        $this->get(route('orders.index', ['number' => $order->id]))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders): bool => $orders->contains($order));

        $this->get(route('orders.index', ['store_number' => 'PRESTA-66']))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders): bool => $orders->contains($order));
    }

    public function test_prestashop_source_uses_configured_integration_name_in_order_views(): void
    {
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => str_repeat('1', 32),
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => '13587',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
        ]);
        $account->orderLinks()->create([
            'order_id' => $order->getKey(),
            'external_order_id' => 13587,
            'external_reference' => '13587',
            'source_hash' => str_repeat('a', 64),
            'imported_at' => now(),
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('<span>multi-click.pl</span>', false);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('<div class="nex-value">multi-click.pl<span', false)
            ->assertSee('<optgroup label="Pozostałe" data-other-source-group>', false)
            ->assertSee('<option value="manual"', false)
            ->assertSee('Ręczne')
            ->assertSee('<optgroup label="PrestaShop" data-prestashop-source-group>', false)
            ->assertSee('<option value="prestashop" selected>multi-click.pl</option>', false);

        $fragment = $this->getJson(route('orders.state', $order).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('<div class="nex-value">multi-click.pl<span', $fragment);
    }

    public function test_prestashop_source_falls_back_to_channel_name_without_integration_link(): void
    {
        $order = Order::query()->create([
            'source' => 'prestashop',
            'external_id' => 'LEGACY-13587',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
        ]);

        $fragment = $this->getJson(route('orders.state', $order).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('<div class="nex-value">PrestaShop<span', $fragment);
    }

    public function test_legacy_order_number_is_moved_before_the_column_is_removed(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_number')->nullable();
        });

        $order = Order::query()->create([
            'source' => 'prestashop',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'order_number' => 'PRESTA-LEGACY-66',
        ]);

        $account = CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'name' => 'InPost Paczkomaty',
            'environment' => 'sandbox',
            'settings' => ['content_description_source' => 'order_number'],
            'is_active' => false,
        ]);

        $migration = require database_path('migrations/2026_07_16_000000_remove_order_number_from_orders_table.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('orders', 'order_number'));
        $this->assertSame('PRESTA-LEGACY-66', $order->fresh()->external_id);
        $this->assertSame('order_id', $account->fresh()->setting('content_description_source'));
    }
}
