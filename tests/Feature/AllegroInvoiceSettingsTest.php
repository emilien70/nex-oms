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

class AllegroInvoiceSettingsTest extends TestCase
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

    public function test_disabled_default_and_actual_statuses_without_initial_status_lock(): void
    {
        $account = app(AllegroAccountService::class)->get();
        $custom = OrderStatusSetting::query()->create(['status' => 'invoice_custom', 'short_name' => 'Gotowe faktury']);
        $deleted = OrderStatusSetting::query()->where('status', 'pending')->firstOrFail();
        $deleted->delete();
        $response = $this->get(route('integrations.allegro.edit', ['tab' => 'invoices']))->assertOk()->assertSee('Gotowe faktury');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//select[@name="invoice_sync_mode"]/option[@value="disabled" and @selected]'));
        $this->assertCount(OrderStatusSetting::query()->count() + 1, $xpath->query('//select[@name="invoice_sync_status_setting_id"]/option'));
        $this->assertCount(1, $xpath->query('//select[@name="invoice_sync_status_setting_id"]/option[@value="'.$custom->id.'"]'));
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->invoice_sync_mode);
        $this->assertFalse($account->fresh()->invoice_sync_only_required);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_saving_initial_and_custom_status_does_not_dispatch_or_contact_allegro(): void
    {
        $account = $this->account();
        $custom = OrderStatusSetting::query()->create(['status' => 'custom', 'short_name' => 'Mój status']);
        foreach ([OrderStatusSetting::query()->where('status', 'new')->value('id'), $custom->id] as $statusId) {
            $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account->fresh(), 'status', $statusId))
                ->assertRedirect(route('integrations.allegro.edit', ['tab' => 'invoices']))->assertSessionHasNoErrors();
            $this->assertSame($statusId, $account->fresh()->invoice_sync_status_setting_id);
        }
        $this->assertDatabaseCount('allegro_invoice_exports', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_validation_and_optimistic_configuration_lock(): void
    {
        $account = $this->account();
        foreach (['wrong', 'status'] as $mode) {
            $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account, $mode))->assertSessionHasErrors();
        }
        $deleted = OrderStatusSetting::query()->where('status', 'pending')->firstOrFail();
        $deleted->delete();
        $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account, 'status', $deleted->id))->assertSessionHasErrors('invoice_sync_status_setting_id');
        $payload = $this->payload($account, 'immediate');
        $this->put(route('integrations.allegro.invoices.settings'), $payload)->assertSessionHasNoErrors();
        $this->put(route('integrations.allegro.invoices.settings'), $payload)->assertSessionHasErrors('invoice_settings');
        $payload = $this->payload($account->fresh(), 'disabled');
        $payload['identity'] = 'production:other';
        $this->put(route('integrations.allegro.invoices.settings'), $payload)->assertSessionHasErrors('invoice_settings');
        $payload = $this->payload($account->fresh(), 'disabled');
        $payload['invoice_sync_only_required'] = 'wrong';
        $this->put(route('integrations.allegro.invoices.settings'), $payload)->assertSessionHasErrors('invoice_sync_only_required');
    }

    public function test_unconnected_account_cannot_enable_automatic_mode(): void
    {
        $account = app(AllegroAccountService::class)->get();
        $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account, 'immediate'))->assertSessionHasErrors('invoice_sync_mode');
        $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account, 'disabled'))->assertSessionHasNoErrors();
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->invoice_sync_mode);
    }

    public function test_disconnect_or_changed_seller_disables_automatic_export(): void
    {
        $account = $this->account();
        $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account, 'immediate'))->assertSessionHasNoErrors();
        app(AllegroAccountService::class)->markConnected($account, ['id' => 'other', 'login' => 'other']);
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->invoice_sync_mode);
        $this->assertNull($account->fresh()->invoice_sync_identity);
        $this->put(route('integrations.allegro.invoices.settings'), $this->payload($account->fresh(), 'immediate'))->assertSessionHasNoErrors();
        app(AllegroAccountService::class)->disconnect($account->fresh());
        $this->assertSame(AllegroInvoiceSyncMode::Disabled, $account->fresh()->invoice_sync_mode);
        $this->assertNull($account->fresh()->invoice_sync_identity);
    }

    private function account(): AllegroAccount
    {
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill(['client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1', 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS])->save();

        return $account;
    }

    private function payload(AllegroAccount $account, string $mode, ?int $statusId = null): array
    {
        return ['identity' => $account->orderImportIdentity(), 'configuration_revision' => $account->invoice_sync_revision,
            'invoice_sync_mode' => $mode, 'invoice_sync_status_setting_id' => $statusId, 'invoice_sync_only_required' => 0];
    }
}
