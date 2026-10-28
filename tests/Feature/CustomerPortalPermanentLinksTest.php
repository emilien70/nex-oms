<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\Invoices\Services\InvoiceIssuingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class CustomerPortalPermanentLinksTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        Storage::fake('local');
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_all_sources_keep_the_code_after_buyer_source_status_amount_and_item_changes(): void
    {
        foreach (['manual', 'allegro', 'prestashop', 'other'] as $source) {
            $order = $this->order(['source' => $source]);
            $url = $this->url($order);
            $link = $this->link($order);
            $stable = $this->stableAttributes($link);
            $order->update(['customer_email' => 'changed@example.test', 'billing_name' => 'Other Buyer',
                'billing_company_name' => 'Other Company', 'billing_tax_id' => '9999999999',
                'shipping_city' => 'Changed City', 'source' => 'manual', 'external_id' => 'CHANGED',
                'status' => Order::STATUS_PENDING, 'total_gross' => '200.00', 'paid_amount' => '100.00']);
            $this->createDocumentItem($order, ['product_name' => 'Changed Product']);
            app(PortalAccessService::class)->provision($order->id);
            $this->artisan('customer-portal:prepare-links')->assertSuccessful();
            $this->assertSame($url, $this->url($order->fresh()));
            $this->assertSame($stable, $this->stableAttributes($link->fresh()));
            $this->get($url)->assertOk()->assertSeeText('Changed Product')->assertDontSee('Other Buyer');
        }
        $this->assertDatabaseCount('customer_portal_links', 4);
        $this->assertDatabaseCount('paynow_payments', 0);
        $this->assertDatabaseCount('order_events', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('personalFields')]
    public function test_personal_changes_and_return_to_previous_values_do_not_revive_verified_access(string $field, string $value): void
    {
        $order = $this->order();
        $settings = PortalSetting::query()->sole();
        $settings->update(['show_address' => true]);
        $url = $this->url($order);
        $oldValue = $order->{$field};
        $generation = $this->link($order)->generation;
        $this->post($url.'/weryfikacja', ['portal_value' => 'Jan Kowalski'])->assertStatus(303);
        $this->get($url)->assertSeeText('Fakturowa');
        $order->update([$field => $value]);
        $this->get($url)->assertOk()->assertDontSeeText('Fakturowa');
        $order->update([$field => $oldValue]);
        $this->assertSame($url, $this->url($order));
        $this->assertSame($generation + 2, $this->link($order)->generation);
        $this->get($url)->assertOk()->assertDontSeeText('Fakturowa');
        $this->post($url.'/weryfikacja', ['portal_value' => 'jan kowalski'])->assertStatus(303);
        $this->get($url)->assertSeeText('Fakturowa');
        Http::assertNothingSent();
    }

    public static function personalFields(): array
    {
        $fields = ['customer_email' => 'other@example.test', 'customer_phone' => '+48 123 456 789'];
        foreach (['shipping', 'billing'] as $prefix) {
            foreach (['name' => 'Other Name', 'company_name' => 'Other Company', 'street' => 'Other Street',
                'building_number' => '99', 'apartment_number' => '8', 'postal_code' => '10-100',
                'city' => 'Other City', 'province' => 'Other Province', 'country_code' => 'DE',
                'phone' => '+49 123456789', 'email' => 'other@example.test'] as $field => $value) {
                $fields[$prefix.'_'.$field] = $value;
            }
        }
        $fields['billing_tax_id'] = '9999999999';

        return array_map(fn ($field, $value) => [$field, $value], array_keys($fields), array_values($fields));
    }

    public function test_pdf_requires_reverification_but_the_admin_pdf_and_snapshot_are_unchanged(): void
    {
        $order = $this->order();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext(now()->format('Y-m-d H:i:s')));
        $before = $invoice->fresh()->getAttributes();
        $url = $this->url($order);
        $pdf = $url.'/dokumenty/'.$invoice->id;
        $this->post($url.'/weryfikacja', ['portal_value' => 'Jan Kowalski'])->assertStatus(303);
        $this->get($pdf)->assertHeader('Content-Type', 'application/pdf');
        $order->update(['billing_name' => 'New Buyer', 'customer_email' => 'new@example.test']);
        $this->get($pdf)->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertSee('name="portal_value"', false);
        $this->post($url.'/weryfikacja', ['portal_value' => 'Jan Kowalski'])->assertStatus(422);
        $this->post($url.'/weryfikacja', ['portal_value' => 'NEW BUYER'])->assertStatus(303);
        $this->get($pdf)->assertHeader('Content-Type', 'application/pdf');
        $this->get('/invoices/'.$invoice->id.'/pdf')->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_transaction_rollback_keeps_the_link_and_verification_revision(): void
    {
        $order = $this->order();
        $link = $this->link($order);
        $before = $link->getAttributes();
        try {
            DB::transaction(function () use ($order): void {
                $order->update(['billing_name' => 'Rolled back Buyer']);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('Jan Kowalski', $order->fresh()->billing_name);
        $this->assertSame($before, $link->fresh()->getAttributes());
    }

    public function test_trash_and_restore_do_not_change_the_url_or_restore_verified_access(): void
    {
        $order = $this->order();
        PortalSetting::query()->sole()->update(['show_address' => true]);
        $url = $this->url($order);
        $this->post($url.'/weryfikacja', ['portal_value' => 'Jan Kowalski'])->assertStatus(303);
        $order->delete();
        $this->get($url)->assertNotFound();
        $order->restore();
        $this->assertSame($url, $this->url($order));
        $this->get($url)->assertOk()->assertDontSeeText('Fakturowa');
    }

    #[DataProvider('immutableFields')]
    public function test_the_model_cannot_replace_or_reassign_a_link(string $field): void
    {
        $order = $this->order();
        $other = $this->order();
        $link = $this->link($order);
        $this->expectException(\LogicException::class);
        $link->update([$field => $field === 'order_id' ? $other->id : str_repeat('a', $field === 'token' ? 10 : 64)]);
    }

    public static function immutableFields(): array
    {
        return [['order_id'], ['token'], ['token_hash'], ['binding_hash']];
    }

    public function test_direct_reassignment_or_binding_corruption_fails_closed(): void
    {
        $order = $this->order();
        $other = $this->order();
        $url = $this->url($order);
        $link = $this->link($order);
        $this->link($other)->delete();
        DB::table('customer_portal_links')->where('id', $link->id)->update(['order_id' => $other->id]);
        $this->get($url)->assertNotFound();
        $this->get('/'.$other->id.'/'.$link->token)->assertNotFound();
        $this->assertNull(app(PortalAccessService::class)->orderLink($other)['url']);
        $this->artisan('customer-portal:bind-links')->assertFailed();
        DB::table('customer_portal_links')->where('id', $link->id)->update(['order_id' => $order->id, 'binding_hash' => str_repeat('0', 64)]);
        $this->get($url)->assertNotFound();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'activate' => 1, 'expected_generation' => 1])->assertUnprocessable();
    }

    public function test_existing_links_are_bound_once_without_changing_tokens_settings_or_inactive_access(): void
    {
        $links = [];
        foreach (['active', 'buyer', 'revoked', 'expired', 'trash'] as $case) {
            $order = $this->order();
            $link = $this->link($order);
            DB::table('customer_portal_links')->where('id', $link->id)->update(['binding_hash' => null]);
            match ($case) {
                'buyer' => $order->update(['billing_name' => 'Changed Buyer']),
                'revoked' => $link->update(['revoked_at' => now()]),
                'expired' => $link->update(['expires_at' => now()->subMinute()]),
                'trash' => $order->delete(),
                default => null,
            };
            $links[$case] = [$order, $link->fresh()->getAttributes()];
        }
        $settings = PortalSetting::query()->sole();
        $settings->update(['is_enabled' => false]);
        $settingsBefore = $settings->fresh()->getAttributes();
        $before = PortalLink::query()->get()->map->getAttributes()->all();
        $this->artisan('customer-portal:bind-links', ['--dry-run' => true])->expectsOutput('Przypisania do uzupelnienia (bez zmian): 5')->assertSuccessful();
        $this->assertSame($before, PortalLink::query()->get()->map->getAttributes()->all());
        $this->artisan('customer-portal:bind-links')->expectsOutput('Uzupelnione stale przypisania: 5')->assertSuccessful();
        $after = PortalLink::query()->get()->map->getAttributes()->all();
        $this->artisan('customer-portal:bind-links')->expectsOutput('Uzupelnione stale przypisania: 0')->assertSuccessful();
        $this->assertSame($after, PortalLink::query()->get()->map->getAttributes()->all());
        $this->assertSame($settingsBefore, $settings->fresh()->getAttributes());
        $settings->update(['is_enabled' => true]);
        foreach ($links as $case => [$order, $original]) {
            $link = $this->link($order);
            foreach (['order_id', 'token', 'token_hash', 'identity_hash', 'expires_at', 'revoked_at', 'created_at'] as $field) {
                $this->assertSame($original[$field], $link->getRawOriginal($field), $case.': '.$field);
            }
            $this->assertSame($original['generation'] + 1, $link->generation);
            $this->assertTrue($link->isBoundTo($order));
            $this->get('/'.$order->id.'/'.$link->token)->assertStatus(in_array($case, ['active', 'buyer'], true) ? 200 : 404);
        }
        Http::assertNothingSent();
    }

    public function test_bound_links_cannot_be_downgraded_by_rolling_back_the_migration(): void
    {
        $this->order();
        $migration = require database_path('migrations/2026_10_27_020000_add_permanent_binding_to_customer_portal_links.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_binding_skips_unsupported_historical_codes_without_rotating_or_enabling_them(): void
    {
        $current = $this->order();
        DB::table('customer_portal_links')->where('order_id', $current->id)->update(['binding_hash' => null]);
        $historical = $this->order();
        $link = $this->link($historical);
        $code = str_repeat('a', 32);
        PortalLink::withoutEvents(fn () => $link->update(['binding_hash' => null, 'token' => $code, 'token_hash' => hash('sha256', $code), 'revoked_at' => now()]));
        $before = $link->fresh()->getAttributes();
        $this->artisan('customer-portal:bind-links', ['--dry-run' => true])
            ->expectsOutput('Przypisania do uzupelnienia (bez zmian): 1')->expectsOutput('Pominiete historyczne kody (bez zmian): 1')->assertSuccessful();
        $this->artisan('customer-portal:bind-links')
            ->expectsOutput('Uzupelnione stale przypisania: 1')->expectsOutput('Pominiete historyczne kody (bez zmian): 1')->assertSuccessful();
        $this->assertSame($before, $link->fresh()->getAttributes());
        $this->assertTrue($this->link($current)->isBoundTo($current));
        $this->get('/zamowienie/'.$code)->assertNotFound();
        $this->get('/'.$historical->id.'/'.$code)->assertNotFound();
        $this->postJson(route('customer-portal.issue', $historical), ['confirm' => 1, 'activate' => 1, 'expected_generation' => 1])->assertUnprocessable();
        DB::table('customer_portal_links')->where('id', $link->id)->update(['token_hash' => str_repeat('0', 64)]);
        $this->artisan('customer-portal:bind-links')->assertFailed();
    }

    public function test_missing_binding_migration_preserves_legacy_validation_and_blocks_activation(): void
    {
        Schema::table('customer_portal_links', fn ($table) => $table->dropColumn('binding_hash'));
        $order = $this->order();
        $url = $this->url($order);
        $this->get($url)->assertOk();
        $order->update(['billing_name' => 'Changed Buyer']);
        $this->get($url)->assertNotFound();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'activate' => 1, 'expected_generation' => 1])->assertUnprocessable();
        $this->artisan('customer-portal:bind-links')->assertFailed();
    }

    public function test_rotation_requests_are_rejected_even_with_a_current_revision(): void
    {
        $order = $this->order();
        $before = $this->link($order)->getAttributes();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'rotate' => 1, 'expected_generation' => 1])->assertUnprocessable();
        $this->assertSame($before, $this->link($order)->getAttributes());
        try {
            app(PortalAccessService::class)->issue($order, true, 1);
            $this->fail('Rotation is forbidden.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $this->link($order)->getAttributes());
    }

    public function test_enabling_active_access_is_read_only_and_requires_a_current_revision(): void
    {
        $order = $this->order();
        $url = $this->url($order);
        $before = $this->link($order)->getAttributes();
        foreach ([1, 1] as $generation) {
            $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'activate' => 1, 'expected_generation' => $generation])
                ->assertOk()->assertJsonPath('url', $url)->assertJsonPath('generation', 1);
        }
        $this->assertSame($before, $this->link($order)->getAttributes());
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'activate' => 1])->assertUnprocessable();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'activate' => 1, 'expected_generation' => 0])->assertUnprocessable();
    }

    public function test_corrupted_encrypted_token_fails_closed_without_displaying_a_url(): void
    {
        $order = $this->order();
        $url = $this->url($order);
        $link = $this->link($order);
        DB::table('customer_portal_links')->where('id', $link->id)->update(['token' => 'invalid-ciphertext']);
        $this->get($url)->assertNotFound();
        $this->assertNull(app(PortalAccessService::class)->orderLink($order)['url']);
        $this->artisan('customer-portal:bind-links')->assertFailed();
    }

    private function order(array $attributes = []): Order
    {
        return $this->createDocumentOrder($attributes + ['purchased_at' => now()])->fresh();
    }

    private function url(Order $order): string
    {
        return app(PortalAccessService::class)->orderLink($order)['url'];
    }

    private function link(Order $order): PortalLink
    {
        return PortalLink::query()->where('order_id', $order->id)->sole();
    }

    private function stableAttributes(PortalLink $link): array
    {
        return array_intersect_key($link->getAttributes(), array_flip(['id', 'order_id', 'token', 'token_hash', 'binding_hash', 'identity_hash', 'expires_at', 'created_at']));
    }
}
