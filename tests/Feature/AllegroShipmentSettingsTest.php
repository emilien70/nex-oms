<?php

namespace Tests\Feature;

use App\Models\OrderStatusSetting;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\Allegro\Enums\AllegroShipmentSyncMode;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Tests\TestCase;

class AllegroShipmentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        OrderStatusSetting::syncDefaults();
    }

    public function test_default_is_disabled_and_statuses_come_from_actual_oms_settings(): void
    {
        $account = app(AllegroAccountService::class)->get();
        OrderStatusSetting::query()->where('status', 'new')->update(['short_name' => 'Moje nowe']);
        $custom = OrderStatusSetting::query()->create(['status' => 'my_dispatch', 'short_name' => 'Moja wysyłka']);
        OrderStatusSetting::query()->where('status', 'pending')->firstOrFail()->delete();
        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'shipments']))->assertOk()->assertSee('Moje nowe')->assertSee('Moja wysyłka');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//select[@name="shipment_sync_mode"]/option[@value="disabled" and @selected]'));
        $this->assertCount(OrderStatusSetting::query()->count() + 1, $xpath->query('//select[@name="shipment_sync_status_setting_id"]/option'));
        $this->assertCount(1, $xpath->query('//select[@name="shipment_sync_status_setting_id"]/option[@value="'.$custom->id.'"]'));
        $this->assertSame(AllegroShipmentSyncMode::Disabled, $account->fresh()->shipment_sync_mode);
        $this->assertDatabaseCount('allegro_shipment_exports', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_status_mode_accepts_initial_or_custom_status_and_does_not_dispatch_anything(): void
    {
        $account = $this->account();
        $custom = OrderStatusSetting::query()->create(['status' => 'custom', 'short_name' => 'Własny']);
        foreach ([OrderStatusSetting::query()->where('status', 'new')->value('id'), $custom->id] as $id) {
            $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account->fresh(), 'status', $id))
                ->assertRedirect(route('integrations.allegro.edit', ['tab' => 'shipments']))->assertSessionHasNoErrors();
            $this->assertSame($id, $account->fresh()->shipment_sync_status_setting_id);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_validation_rejects_missing_deleted_unknown_statuses_and_invalid_modes(): void
    {
        $account = $this->account();
        $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account, 'status'))
            ->assertSessionHasErrors('shipment_sync_status_setting_id');
        $deleted = OrderStatusSetting::query()->where('status', 'pending')->firstOrFail();
        $deleted->delete();
        foreach ([$deleted->id, 999999] as $id) {
            $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account, 'status', $id))
                ->assertSessionHasErrors('shipment_sync_status_setting_id');
        }
        $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account, 'always'))
            ->assertSessionHasErrors('shipment_sync_mode');
        $this->assertSame(0, $account->fresh()->shipment_sync_revision);
        $this->assertSame(AllegroShipmentSyncMode::Disabled, $account->fresh()->shipment_sync_mode);
    }

    public function test_concurrent_or_foreign_identity_save_cannot_overwrite_configuration(): void
    {
        $account = $this->account();
        $payload = $this->payload($account, 'immediate');
        $this->put(route('integrations.allegro.shipments.settings'), $payload)->assertSessionHasNoErrors();
        $this->put(route('integrations.allegro.shipments.settings'), $payload)->assertSessionHasErrors('shipment_settings');
        $payload = $this->payload($account->fresh(), 'disabled');
        $payload['identity'] = 'sandbox:other';
        $this->put(route('integrations.allegro.shipments.settings'), $payload)->assertSessionHasErrors('shipment_settings');
        $this->assertSame(AllegroShipmentSyncMode::Immediate, $account->fresh()->shipment_sync_mode);
    }

    public function test_disconnected_account_can_save_disabled_but_cannot_enable_export(): void
    {
        $account = app(AllegroAccountService::class)->get();
        $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account, 'immediate'))->assertSessionHasErrors('shipment_sync_mode');
        $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account, 'disabled'))->assertSessionHasNoErrors();
        $this->assertSame(AllegroShipmentSyncMode::Disabled, $account->fresh()->shipment_sync_mode);
    }

    public function test_seller_change_and_disconnect_disable_export_but_retest_preserves_it(): void
    {
        $account = $this->account();
        $this->put(route('integrations.allegro.shipments.settings'), $this->payload($account, 'immediate'))->assertSessionHasNoErrors();
        $accounts = app(AllegroAccountService::class);
        $accounts->markConnected($account, ['id' => 'seller-1', 'login' => 'same']);
        $this->assertSame(AllegroShipmentSyncMode::Immediate, $account->fresh()->shipment_sync_mode);
        $accounts->markConnected($account, ['id' => 'other', 'login' => 'other']);
        $this->assertSame(AllegroShipmentSyncMode::Disabled, $account->fresh()->shipment_sync_mode);
        $this->assertNull($account->fresh()->shipment_sync_identity);
        $this->assertSame(2, $account->fresh()->shipment_sync_revision);
        $accounts->disconnect($account);
        $this->assertSame(3, $account->fresh()->shipment_sync_revision);
    }

    private function account(): AllegroAccount
    {
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill([
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1',
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS,
        ])->save();

        return $account;
    }

    private function payload(AllegroAccount $account, string $mode, ?int $statusId = null): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->shipment_sync_revision,
            'shipment_sync_mode' => $mode, 'shipment_sync_status_setting_id' => $statusId];
    }
}
