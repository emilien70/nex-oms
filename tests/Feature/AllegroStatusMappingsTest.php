<?php

namespace Tests\Feature;

use App\Models\OrderStatusSetting;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Tests\TestCase;

class AllegroStatusMappingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        OrderStatusSetting::syncDefaults();
    }

    public function test_actual_oms_statuses_are_editable_and_default_to_no_export(): void
    {
        $account = $this->account();
        OrderStatusSetting::query()->where('status', 'new')->update(['short_name' => 'Moje nowe']);
        $custom = OrderStatusSetting::query()->create([
            'status' => 'custom_service', 'short_name' => 'Serwis własny', 'sort_order' => 20,
        ]);
        OrderStatusSetting::query()->where('status', 'pending')->firstOrFail()->delete();
        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'statuses']))->assertOk()
            ->assertSee('Moje nowe')->assertSee('Serwis własny')
            ->assertDontSee('Zablokowane')->assertDontSee('value="RETURNED"', false)
            ->assertDontSee('data-allegro-status-row="pending"', false);

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $selects = $xpath->query('//select[starts-with(@name,"outbound[")]');
        $this->assertCount(OrderStatusSetting::query()->count(), $selects);
        foreach ($selects as $select) {
            $this->assertFalse($select->hasAttribute('disabled'));
            $selected = $xpath->query('option[@selected]', $select);
            $this->assertCount(1, $selected);
            $this->assertSame('', $selected->item(0)->getAttribute('value'));
            $this->assertSame('Nie przekazuj', $selected->item(0)->textContent);
            $this->assertCount(9, $xpath->query('option', $select));
        }
        $this->assertCount(1, $xpath->query('//select[@name="outbound['.$custom->id.']"]'));
        $this->assertFalse($account->fresh()->status_sync_enabled);
        $this->assertDatabaseCount('allegro_outbound_status_mappings', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_new_and_multiple_oms_statuses_can_map_to_the_same_target_without_backfill(): void
    {
        $account = $this->account();
        $new = OrderStatusSetting::query()->where('status', 'new')->value('id');
        $pending = OrderStatusSetting::query()->where('status', 'pending')->value('id');
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [
            $new => 'PROCESSING', $pending => 'PROCESSING',
        ], true))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($account->fresh()->status_sync_enabled);
        $this->assertSame(1, $account->fresh()->status_sync_revision);
        $this->assertDatabaseCount('allegro_outbound_status_mappings', 2);
        foreach ([$new, $pending] as $id) {
            $this->assertDatabaseHas('allegro_outbound_status_mappings', [
                'order_status_setting_id' => $id, 'allegro_status' => 'PROCESSING',
                'environment' => 'production', 'seller_user_id' => 'seller-1',
            ]);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_invalid_targets_status_ids_and_booleans_preserve_the_configuration(): void
    {
        $account = $this->account();
        $id = OrderStatusSetting::query()->where('status', 'new')->value('id');
        $valid = $this->payload($account, [$id => 'NEW']);
        $this->put(route('integrations.allegro.statuses.update'), $valid)->assertSessionHasNoErrors();
        $account->refresh();
        foreach (['RETURNED', 'NOT_A_STATUS', ['SENT']] as $target) {
            $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [$id => $target]))
                ->assertSessionHasErrors('outbound.'.$id);
        }
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [999999 => 'SENT']))
            ->assertSessionHasErrors('outbound');
        $invalid = $this->payload($account);
        $invalid['status_sync_enabled'] = 'yes';
        $this->put(route('integrations.allegro.statuses.update'), $invalid)->assertSessionHasErrors('status_sync_enabled');
        $this->assertSame(1, $account->fresh()->status_sync_revision);
        $this->assertDatabaseHas('allegro_outbound_status_mappings', ['order_status_setting_id' => $id, 'allegro_status' => 'NEW']);
        $this->assertDatabaseCount('allegro_outbound_status_mappings', 1);
    }

    public function test_stale_revision_identity_and_deleted_status_are_rejected(): void
    {
        $account = $this->account();
        $payload = $this->payload($account);
        $this->put(route('integrations.allegro.statuses.update'), $payload)->assertSessionHasNoErrors();
        $this->put(route('integrations.allegro.statuses.update'), $payload)->assertSessionHasErrors('status_mappings');
        $account->refresh();
        $payload = $this->payload($account);
        $payload['identity'] = 'sandbox:someone-else';
        $this->put(route('integrations.allegro.statuses.update'), $payload)->assertSessionHasErrors('status_mappings');
        $deleted = OrderStatusSetting::query()->where('status', 'pending')->firstOrFail();
        $deleted->delete();
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [$deleted->id => 'SENT']))
            ->assertSessionHasErrors('outbound');
        $this->assertSame(1, $account->fresh()->status_sync_revision);
    }

    public function test_disconnected_account_cannot_enable_sync_but_can_save_no_export(): void
    {
        $account = app(AllegroAccountService::class)->get();
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [], true))
            ->assertSessionHasErrors('status_sync_enabled');
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account))
            ->assertSessionHasNoErrors();
        $this->assertFalse($account->fresh()->status_sync_enabled);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_retesting_same_account_keeps_config_but_switching_seller_disables_it(): void
    {
        $account = $this->account();
        $id = OrderStatusSetting::query()->where('status', 'new')->value('id');
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [$id => 'NEW'], true))
            ->assertSessionHasNoErrors();
        $accounts = app(AllegroAccountService::class);
        $accounts->markConnected($account, ['id' => 'seller-1', 'login' => 'same']);
        $this->assertTrue($account->fresh()->status_sync_enabled);
        $this->assertSame(1, $account->fresh()->status_sync_revision);
        $accounts->markConnected($account, ['id' => 'seller-2', 'login' => 'other']);
        $this->assertFalse($account->fresh()->status_sync_enabled);
        $this->assertNull($account->fresh()->status_sync_identity);
        $this->assertSame(2, $account->fresh()->status_sync_revision);
        $this->get(route('integrations.allegro.edit', ['tab' => 'statuses']))->assertOk()
            ->assertDontSee('value="NEW" selected', false);
        $this->assertDatabaseCount('allegro_outbound_status_mappings', 1);
        $accounts->disconnect($account);
        $this->assertSame(3, $account->fresh()->status_sync_revision);
    }

    public function test_none_removes_mapping_and_new_statuses_start_unmapped(): void
    {
        $account = $this->account();
        $id = OrderStatusSetting::query()->where('status', 'new')->value('id');
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account, [$id => 'NEW']))->assertSessionHasNoErrors();
        $custom = OrderStatusSetting::query()->create(['status' => 'later', 'short_name' => 'Później']);
        $this->get(route('integrations.allegro.edit', ['tab' => 'statuses']))->assertOk()->assertSee('Później');
        $this->assertDatabaseMissing('allegro_outbound_status_mappings', ['order_status_setting_id' => $custom->id]);
        $this->put(route('integrations.allegro.statuses.update'), $this->payload($account->fresh(), [$id => '']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('allegro_outbound_status_mappings', 0);
    }

    private function account(): AllegroAccount
    {
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill([
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1',
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'is_active' => true,
            'last_test_status' => AllegroAccount::STATUS_SUCCESS,
        ])->save();

        return $account;
    }

    private function payload(AllegroAccount $account, array $outbound = [], bool $enabled = false): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->status_sync_revision,
            'outbound' => $outbound, 'status_sync_enabled' => $enabled ? '1' : '0'];
    }
}
