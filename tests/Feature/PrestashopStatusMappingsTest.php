<?php

namespace Tests\Feature;

use App\Models\OrderStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Tests\TestCase;

class PrestashopStatusMappingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statuses_tab_lists_both_mapping_directions(): void
    {
        $account = $this->account();
        $account->orderStates()->createMany([
            $this->state(1, 'Oczekiwanie na płatność'),
            $this->state(4, 'Wysłane', ['shipped' => true]),
        ]);

        $this->get(route('integrations.prestashop.edit', ['tab' => 'statuses']))
            ->assertOk()
            ->assertSeeText('Pobieranie statusów z PrestaShop')
            ->assertSeeText('Przekazywanie statusów do PrestaShop')
            ->assertSee('name="inbound[1]"', false)
            ->assertSee('name="outbound[new]"', false)
            ->assertSeeText('Nie zmieniaj statusu w OMS')
            ->assertSeeText('Nie przekazuj do PrestaShop')
            ->assertSeeText('Automatycznie pobieraj zmiany statusów')
            ->assertSeeText('Synchronizuj teraz')
            ->assertSeeText('Wysyłaj klientowi e-mail z PrestaShop po przekazaniu statusu');
    }

    public function test_status_mappings_are_saved_without_contacting_prestashop(): void
    {
        Http::fake();
        $account = $this->account();
        $account->orderStates()->createMany([
            $this->state(1, 'Oczekiwanie na płatność'),
            $this->state(4, 'Wysłane', ['shipped' => true]),
        ]);
        OrderStatusSetting::syncDefaults();
        $newStatusId = OrderStatusSetting::query()->where('status', 'new')->value('id');
        $shippedStatusId = OrderStatusSetting::query()->where('status', 'shipped')->value('id');

        $this->put(route('integrations.prestashop.status-mappings.update'), [
            'inbound' => [
                1 => 'new',
                4 => 'shipped',
            ],
            'outbound' => [
                'new' => 1,
                'shipped' => 4,
            ],
            'notify_customer_on_status_change' => '1',
            'status_sync_enabled' => '1',
            'status_sync_interval_minutes' => '5',
        ])
            ->assertRedirect(route('integrations.prestashop.edit', ['tab' => 'statuses']))
            ->assertSessionHas('success', 'Mapowanie statusów PrestaShop zostało zapisane.');

        $this->assertDatabaseHas('prestashop_inbound_status_mappings', [
            'prestashop_account_id' => $account->id,
            'external_order_state_id' => 1,
            'order_status_setting_id' => $newStatusId,
        ]);
        $this->assertDatabaseHas('prestashop_inbound_status_mappings', [
            'prestashop_account_id' => $account->id,
            'external_order_state_id' => 4,
            'order_status_setting_id' => $shippedStatusId,
        ]);
        $this->assertDatabaseHas('prestashop_outbound_status_mappings', [
            'prestashop_account_id' => $account->id,
            'order_status_setting_id' => $newStatusId,
            'external_order_state_id' => 1,
        ]);
        $this->assertDatabaseHas('prestashop_outbound_status_mappings', [
            'prestashop_account_id' => $account->id,
            'order_status_setting_id' => $shippedStatusId,
            'external_order_state_id' => 4,
        ]);
        $this->assertTrue($account->fresh()->notify_customer_on_status_change);
        $this->assertTrue($account->fresh()->status_sync_enabled);
        $this->assertSame(5, $account->fresh()->status_sync_interval_minutes);
        Http::assertNothingSent();
    }

    public function test_unavailable_status_cannot_be_selected_for_outbound_mapping(): void
    {
        $account = $this->account();
        $account->orderStates()->create($this->state(9, 'Status usunięty', [
            'is_available' => false,
        ]));

        $this->from(route('integrations.prestashop.edit', ['tab' => 'statuses']))
            ->put(route('integrations.prestashop.status-mappings.update'), [
                'inbound' => [9 => ''],
                'outbound' => ['new' => 9],
                'notify_customer_on_status_change' => '0',
                'status_sync_enabled' => '0',
                'status_sync_interval_minutes' => '1',
            ])
            ->assertSessionHasErrors('outbound.new');

        $this->assertDatabaseCount('prestashop_outbound_status_mappings', 0);
    }

    public function test_unknown_oms_status_is_rejected_without_replacing_existing_mappings(): void
    {
        $account = $this->account();
        $account->orderStates()->create($this->state(1, 'Oczekiwanie na płatność'));
        OrderStatusSetting::syncDefaults();
        $newStatus = OrderStatusSetting::query()->where('status', 'new')->firstOrFail();
        $account->inboundStatusMappings()->create([
            'external_order_state_id' => 1,
            'order_status_setting_id' => $newStatus->id,
        ]);

        $this->from(route('integrations.prestashop.edit', ['tab' => 'statuses']))
            ->put(route('integrations.prestashop.status-mappings.update'), [
                'inbound' => [1 => 'nieistniejacy-status'],
                'outbound' => [],
                'notify_customer_on_status_change' => '0',
                'status_sync_enabled' => '0',
                'status_sync_interval_minutes' => '1',
            ])
            ->assertSessionHasErrors('inbound.1');

        $this->assertDatabaseHas('prestashop_inbound_status_mappings', [
            'prestashop_account_id' => $account->id,
            'external_order_state_id' => 1,
            'order_status_setting_id' => $newStatus->id,
        ]);
    }

    public function test_soft_deleted_oms_status_cannot_be_saved_as_an_active_target(): void
    {
        $account = $this->account();
        $account->orderStates()->create($this->state(1, 'Oczekiwanie na płatność'));
        OrderStatusSetting::syncDefaults();
        $newStatus = OrderStatusSetting::query()->where('status', 'new')->firstOrFail();
        $mapping = $account->inboundStatusMappings()->create([
            'external_order_state_id' => 1,
            'order_status_setting_id' => $newStatus->id,
        ]);
        $newStatus->delete();

        $this->assertNull($mapping->fresh()->statusSetting);

        $this->from(route('integrations.prestashop.edit', ['tab' => 'statuses']))
            ->put(route('integrations.prestashop.status-mappings.update'), [
                'inbound' => [1 => 'new'],
                'outbound' => [],
                'notify_customer_on_status_change' => '0',
                'status_sync_enabled' => '0',
                'status_sync_interval_minutes' => '1',
            ])
            ->assertSessionHasErrors('inbound.1');
    }

    public function test_api_key_change_preserves_mappings_but_store_change_clears_them(): void
    {
        $account = $this->account();
        $account->orderStates()->create($this->state(1, 'Oczekiwanie na płatność'));
        OrderStatusSetting::syncDefaults();
        $newStatus = OrderStatusSetting::query()->where('status', 'new')->firstOrFail();
        $account->inboundStatusMappings()->create([
            'external_order_state_id' => 1,
            'order_status_setting_id' => $newStatus->id,
        ]);

        $this->put(route('integrations.prestashop.update'), $this->settingsPayload([
            'api_key' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
        ]))->assertRedirect(route('integrations.prestashop.edit'));

        $this->assertDatabaseCount('prestashop_inbound_status_mappings', 1);

        $this->put(route('integrations.prestashop.update'), $this->settingsPayload([
            'base_url' => 'https://shop.multi-click.pl',
            'product_url_template' => 'https://shop.multi-click.pl/index.php?controller=product&id_product=[product_id]',
        ]))->assertRedirect(route('integrations.prestashop.edit'));

        $this->assertDatabaseCount('prestashop_inbound_status_mappings', 0);
    }

    private function account(array $overrides = []): PrestashopAccount
    {
        return PrestashopAccount::query()->create(array_merge([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
            'last_tested_at' => now(),
            'last_test_status' => PrestashopConnectionTestStatus::Success,
            'last_test_message' => 'OK',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'product_url_template' => 'https://multi-click.pl/index.php?controller=product&id_product=[product_id]',
            'api_key' => '',
            'is_active' => '0',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function state(int $externalId, string $name, array $overrides = []): array
    {
        return array_merge([
            'external_id' => $externalId,
            'name' => $name,
            'is_available' => true,
            'refreshed_at' => now(),
        ], $overrides);
    }
}
