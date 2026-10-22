<?php

namespace Tests\Feature;

use App\Models\OrderStatusSetting;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\Allegro\Enums\AllegroInvoiceSyncMode;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Tests\TestCase;

class AllegroCorrectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        OrderStatusSetting::syncDefaults();
    }

    public function test_default_is_disabled_and_status_list_is_dynamic_without_initial_lock(): void
    {
        $account = app(AllegroAccountService::class)->get();
        $custom = OrderStatusSetting::query()->create(['status' => 'custom', 'short_name' => 'Własny status']);
        OrderStatusSetting::query()->where('status', 'pending')->firstOrFail()->delete();
        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'invoices']))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//select[@name="correction_sync_mode"]/option[@selected and @value="disabled"]'));
        $this->assertCount(OrderStatusSetting::query()->count() + 1, $xpath->query('//select[@name="correction_sync_status_setting_id"]/option'));
        $this->assertCount(1, $xpath->query('//select[@name="correction_sync_status_setting_id"]/option[@value="'.$custom->id.'"]'));
        $response->assertSee('Po zamknięciu korekty')->assertDontSee('Zablokowane');
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->correction_sync_mode);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_saving_initial_and_custom_status_is_independent_and_does_not_dispatch(): void
    {
        $account = $this->account();
        $custom = OrderStatusSetting::query()->create(['status' => 'custom', 'short_name' => 'Status własny']);
        foreach ([OrderStatusSetting::query()->where('status', 'new')->value('id'), $custom->id] as $id) {
            $this->put(route('integrations.allegro.corrections.settings'), $this->payload($account->fresh(), 'status', $id))
                ->assertSessionHasNoErrors()->assertRedirect(route('integrations.allegro.edit', ['tab' => 'invoices']));
            $this->assertSame($id, $account->fresh()->correction_sync_status_setting_id);
        }
        $this->assertSame(0, $account->fresh()->invoice_sync_revision);
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->invoice_sync_mode);
        $this->assertDatabaseCount('allegro_invoice_exports', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_validation_identity_revision_and_connection_are_required(): void
    {
        $account = app(AllegroAccountService::class)->get();
        $url = route('integrations.allegro.corrections.settings');
        $this->put($url, $this->payload($account, 'immediate'))->assertSessionHasErrors('correction_sync_mode');
        $account = $this->account();
        $this->put($url, $this->payload($account, 'bad'))->assertSessionHasErrors('correction_sync_mode');
        $this->put($url, $this->payload($account, 'status'))->assertSessionHasErrors('correction_sync_status_setting_id');
        $deleted = OrderStatusSetting::query()->where('status', 'pending')->firstOrFail();
        $deleted->delete();
        $this->put($url, $this->payload($account, 'status', $deleted->id))->assertSessionHasErrors('correction_sync_status_setting_id');
        $payload = $this->payload($account, 'immediate');
        $this->put($url, array_replace($payload, ['identity' => 'production:other']))->assertSessionHasErrors('invoice_settings');
        $this->put($url, $payload)->assertSessionHasNoErrors();
        $this->put($url, $payload)->assertSessionHasErrors('invoice_settings');
    }

    public function test_reconnection_to_same_seller_preserves_settings_but_new_identity_and_disconnect_disable_them(): void
    {
        $account = $this->account();
        $account->update(['correction_sync_mode' => 'immediate', 'correction_sync_identity' => $account->orderImportIdentity()]);
        app(AllegroAccountService::class)->markConnected($account, ['id' => 'seller-1', 'login' => 'multi-click']);
        $this->assertSame(AllegroInvoiceSyncMode::Immediate, $account->fresh()->correction_sync_mode);
        app(AllegroAccountService::class)->markConnected($account->fresh(), ['id' => 'other', 'login' => 'other']);
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->correction_sync_mode);
        $this->assertNull($account->fresh()->correction_sync_identity);
        $account->refresh()->update(['correction_sync_mode' => 'immediate']);
        app(AllegroAccountService::class)->disconnect($account);
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->correction_sync_mode);
        $this->assertNull($account->fresh()->correction_sync_identity);
    }

    public function test_deleted_selected_status_leaves_configuration_without_destructive_cascade(): void
    {
        $account = $this->account();
        $status = OrderStatusSetting::query()->create(['status' => 'temporary', 'short_name' => 'Tymczasowy']);
        $account->update(['correction_sync_mode' => 'status', 'correction_sync_status_setting_id' => $status->id]);
        $status->forceDelete();
        $this->assertNull($account->fresh()->correction_sync_status_setting_id);
        $this->assertSame(AllegroInvoiceSyncMode::Status, $account->fresh()->correction_sync_mode);
    }

    private function account(): AllegroAccount
    {
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill(['client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1', 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS])->save();

        return $account;
    }

    private function payload(AllegroAccount $account, string $mode, ?int $id = null): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->correction_sync_revision,
            'correction_sync_mode' => $mode, 'correction_sync_status_setting_id' => $id];
    }
}
