<?php

namespace Tests\Feature;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Exceptions\OrderEmailException;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Services\OrderEmailPreparationService;
use App\Services\OrderVariableService;
use App\ValueObjects\OrderEmailPreparationData;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Models\PortalStatus;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\CustomerPortal\Services\PortalDocumentService;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\ProformaService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Products\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\BuildsCustomerPortalRoutes;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use BuildsCustomerPortalRoutes;
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_new_order_has_active_access_and_get_settings_and_preview_do_not_change_it(): void
    {
        $order = $this->order();
        $this->get(route('customer-portal.settings'))->assertOk()->assertSeeText('Strony informacyjne');
        $this->get(route('customer-portal.preview', $order))->assertOk()->assertSeeText('Podgląd administratora');
        $this->assertTrue(PortalSetting::query()->sole()->is_enabled);
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1])->assertOk();
        $this->assertDatabaseCount('customer_portal_links', 1);
        $this->assertDatabaseCount('customer_portal_settings', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('sources')]
    public function test_customer_view_uses_only_public_fields_and_works_for_all_order_sources(string $source): void
    {
        $this->settings();
        $order = $this->order(['source' => $source, 'status' => 'internal-custom']);
        $setting = OrderStatusSetting::query()->create(['status' => 'internal-custom', 'sort_order' => 99,
            'color' => '#123456', 'short_name' => 'Wyslane przez sekretnego pracownika', 'full_name' => 'WEWNETRZNA NOTATKA']);
        PortalStatus::query()->create(['order_status_setting_id' => $setting->id, 'is_visible' => true,
            'public_name' => 'Przygotowujemy zamówienie', 'public_message' => 'Twoje produkty są przygotowywane.']);
        $this->createDocumentItem($order, ['product_name' => '<script>alert(1)</script> Produkt klienta', 'sku' => 'PRIVATE-SKU']);
        $url = $this->url($order);
        $before = $order->fresh()->getAttributes();
        $linkBefore = PortalLink::query()->sole()->getAttributes();
        $this->get($url)->assertOk()->assertSeeText('Przygotowujemy zamówienie')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('PRIVATE-SKU')->assertDontSee('INTERNAL-SECRET')->assertDontSee('klient@example.test')
            ->assertDontSee('Wyslane przez sekretnego pracownika')->assertDontSee('WEWNETRZNA NOTATKA')->assertDontSee('Dostawcza');
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($linkBefore, PortalLink::query()->sole()->getAttributes());
        $this->assertDatabaseCount('order_events', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function sources(): array
    {
        return [['manual'], ['prestashop'], ['allegro']];
    }

    public function test_unmapped_status_does_not_fall_back_to_internal_names(): void
    {
        $this->settings();
        $order = $this->order(['status' => 'PRIVATE-CODE']);
        $this->get($this->url($order))->assertOk()->assertSeeText('Zamówienie zarejestrowane')->assertDontSee('PRIVATE-CODE');
    }

    public function test_link_copy_is_stable_encrypted_and_get_and_head_are_read_only(): void
    {
        $this->settings();
        $order = $this->order();
        $response = $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1])->assertOk();
        $url = $response->json('url');
        $this->assertMatchesRegularExpression('~/[1-9][0-9]*/[A-Za-z0-9]{10}$~D', $url);
        $this->assertSame($url, $this->url($order));
        $link = PortalLink::query()->sole();
        $this->assertNotSame(basename($url), DB::table('customer_portal_links')->value('token'));
        $this->assertArrayNotHasKey('token', $link->toArray());
        $this->assertArrayNotHasKey('token_hash', $link->toArray());
        $before = $link->getAttributes();
        foreach (['GET', 'HEAD', 'GET'] as $method) {
            $response = $this->call($method, $url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($before, $link->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_revoke_and_rotation_protect_against_stale_and_repeated_requests(): void
    {
        $this->settings();
        $order = $this->order();
        $url = $this->url($order);
        $this->post(route('customer-portal.revoke', $order), ['confirm' => 1, 'expected_generation' => 1])->assertRedirect();
        $this->get($url)->assertNotFound()->assertDontSee('123,00');
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1])->assertUnprocessable();
        $data = ['confirm' => 1, 'rotate' => 1, 'expected_generation' => 1];
        $new = $this->postJson(route('customer-portal.issue', $order), $data)->assertOk()->json('url');
        $this->assertNotSame($url, $new);
        $this->get($new)->assertOk();
        $this->get($url)->assertNotFound();
        $this->postJson(route('customer-portal.issue', $order), $data)->assertUnprocessable();
        $this->postJson(route('customer-portal.revoke', $order), ['confirm' => 1, 'expected_generation' => 1])->assertUnprocessable();
        $this->get($new)->assertOk();
        $this->assertDatabaseCount('customer_portal_links', 1);
    }

    #[DataProvider('deniedScenarios')]
    public function test_access_is_rechecked_for_customer_changes_expiry_disable_and_deletion(string $scenario): void
    {
        $settings = $this->settings();
        $order = $this->order();
        $url = $this->url($order);
        if ($scenario === 'expiry') {
            $this->travel(91)->days();
        } elseif ($scenario === 'email') {
            $order->update(['customer_email' => 'another@example.test']);
        } elseif ($scenario === 'buyer') {
            $order->update(['billing_tax_id' => '1111111111']);
        } elseif ($scenario === 'disabled') {
            $settings->update(['is_enabled' => false]);
        } elseif ($scenario === 'shortened validity') {
            $order->update(['purchased_at' => now()->subDays(10)]);
            $settings->update(['availability_days' => 5]);
        } else {
            $order->delete();
        }
        $this->get($url)->assertNotFound()->assertDontSee('klient@example.test')->assertDontSee('123,00');
        Http::assertNothingSent();
    }

    public static function deniedScenarios(): array
    {
        return [['expiry'], ['email'], ['buyer'], ['disabled'], ['shortened validity'], ['deleted']];
    }

    public function test_bogus_tokens_query_parameters_and_order_numbers_do_not_grant_other_orders(): void
    {
        $this->settings();
        $order = $this->order();
        $other = $this->order(['billing_name' => 'OTHER-BUYER', 'total_gross' => '999.00']);
        $this->get('/zamowienie/'.str_repeat('z', 32))->assertNotFound();
        $this->get('/zamowienie/'.$other->id)->assertNotFound();
        $this->get($this->url($order).'?order='.$other->id)->assertOk()->assertDontSee('OTHER-BUYER')->assertDontSee('999,00');
    }

    public function test_address_and_branding_settings_are_respected_without_exposing_other_contact_fields(): void
    {
        $this->settings(['show_address' => true, 'customer_message' => '<script>private()</script>', 'contact_email' => 'help@example.test']);
        $order = $this->order();
        $this->get($this->url($order))->assertOk()->assertSeeText('Dostawcza')->assertSee('help@example.test')
            ->assertSee('&lt;script&gt;private()&lt;/script&gt;', false)->assertDontSee('<script>private()</script>', false)
            ->assertDontSee('odbiorca@example.test');
    }

    public function test_multiple_shipments_show_creation_separately_from_dispatch_and_hide_cancelled_records(): void
    {
        $this->settings();
        $order = $this->order();
        foreach (['CREATED-1' => 'created', 'DISPATCHED-2' => 'dispatched'] as $number => $status) {
            $order->shipments()->create(['provider' => 'inpost_courier', 'service' => 'inpost_courier_standard', 'request_uuid' => (string) Str::uuid(), 'tracking_number' => $number, 'oms_status' => $status, 'status' => $status === 'created' ? 'created' : 'taken_by_courier']);
        }
        $order->shipments()->create(['provider' => 'dpd', 'service' => 'dpd', 'request_uuid' => (string) Str::uuid(), 'tracking_number' => 'CANCELLED-PRIVATE', 'oms_status' => 'created', 'status' => 'cancelled']);
        $this->get($this->url($order))->assertOk()->assertSeeText('Przesyłka utworzona')->assertSeeText('Przesyłka nadana')
            ->assertSee('CREATED-1')->assertSee('DISPATCHED-2')->assertDontSee('CANCELLED-PRIVATE')->assertSee('https://inpost.pl/sledzenie-przesylek');
        Http::assertNothingSent();
    }

    public function test_private_product_images_and_logo_require_the_matching_live_grant(): void
    {
        $this->settings(['logo_path' => 'customer-portal/logos/logo.png']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aCwoAAAAASUVORK5CYII=');
        Storage::disk('local')->put('customer-portal/logos/logo.png', $png);
        $product = Product::query()->create(['name' => 'Product', 'price_gross' => '100.00', 'currency' => 'PLN', 'stock_quantity' => 1]);
        $path = 'products/'.$product->id.'/photo.png';
        Storage::disk('local')->put($path, $png);
        $product->update(['image_path' => $path]);
        $order = $this->order();
        $item = $this->createDocumentItem($order, ['product_id' => $product->id]);
        $foreign = $this->createDocumentItem($this->order(), ['product_id' => $product->id]);
        $token = basename($this->url($order));
        $image = $this->portalRoute('customer-portal.image', ['token' => $token, 'item' => $item->id]);
        $logo = $this->portalRoute('customer-portal.logo', $token);
        $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($logo)->assertOk();
        $this->get($this->portalRoute('customer-portal.image', ['token' => $token, 'item' => $foreign->id]))->assertNotFound();
        app(PortalAccessService::class)->revoke($order, 1);
        $this->get($image)->assertNotFound();
        $this->get($logo)->assertNotFound();
    }

    public function test_invoice_pdf_download_is_order_scoped_and_does_not_change_document_or_numbering(): void
    {
        $this->settings();
        $order = $this->order();
        $invoice = $this->invoice($order);
        $foreign = $this->invoice($this->order());
        $token = basename($this->url($order));
        $before = $invoice->fresh()->getAttributes();
        $counters = DB::table('invoice_number_counters')->get()->toJson();
        $url = $this->portalRoute('customer-portal.document', ['token' => $token, 'invoice' => $invoice->id]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($this->portalRoute('customer-portal.document', ['token' => $token, 'invoice' => $foreign->id]))->assertNotFound();
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($counters, DB::table('invoice_number_counters')->get()->toJson());
        app(PortalAccessService::class)->revoke($order, 1);
        $this->get($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_document_visibility_draft_and_unfinalized_correction_guards(): void
    {
        $settings = $this->settings();
        $order = $this->order();
        $invoice = $this->invoice($order);
        $documents = app(PortalDocumentService::class);
        $this->assertTrue($documents->available($invoice, $settings));
        $this->assertFalse($documents->available($invoice, new PortalSetting(['show_invoices' => false])));
        $draft = $invoice->replicate();
        $draft->status = 'draft';
        $this->assertFalse($documents->available($draft, $settings));
        $correction = $invoice->replicate();
        $correction->document_type = InvoiceDocumentType::Correction;
        $correction->corrected_invoice_id = $invoice->id;
        $correction->number = 'KOR 1/2026';
        $correction->sequence_number = null;
        $correction->numbering_period_key = null;
        $correction->save();
        $this->assertFalse($documents->available($correction, $settings));
        $correction->forceFill(['finalized_at' => now()])->save();
        $this->assertTrue($documents->available($correction->fresh(), $settings));
        $correction->update(['corrected_invoice_id' => $this->invoice($this->order())->id]);
        $this->assertFalse($documents->available($correction->fresh(), $settings));
    }

    #[DataProvider('blockedKsefStates')]
    public function test_ksef_series_without_final_accepted_document_never_falls_back_to_standard_pdf(string $state): void
    {
        $settings = $this->settings();
        $invoice = $this->invoice($this->order());
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Production]);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $invoice->invoice_series_id, 'is_enabled' => true]);
        if ($state !== 'no submission') {
            $this->submission($invoice, KsefEnvironment::Production, $state);
        }
        $this->assertFalse(app(PortalDocumentService::class)->available($invoice, $settings));
        $this->get($this->portalRoute('customer-portal.document', ['token' => basename($this->url($invoice->order)), 'invoice' => $invoice->id]))->assertNotFound();
        Http::assertNothingSent();
    }

    public static function blockedKsefStates(): array
    {
        return [['no submission'], ['preparing'], ['session_opened'], ['submitted'], ['processing'], ['uncertain'], ['rejected'], ['technical_failed']];
    }

    public function test_accepted_production_is_available_but_demo_is_only_an_admin_preview(): void
    {
        $settings = $this->settings();
        $invoice = $this->invoice($this->order());
        $setting = KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Production]);
        $this->submission($invoice, KsefEnvironment::Production, 'accepted');
        $documents = app(PortalDocumentService::class);
        $this->assertTrue($documents->available($invoice, $settings));
        $publicDocument = $this->portalRoute('customer-portal.document', ['token' => basename($this->url($invoice->order)), 'invoice' => $invoice->id]);
        $this->get($publicDocument)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $setting->update(['environment' => KsefEnvironment::Demo]);
        $this->submission($invoice, KsefEnvironment::Demo, 'accepted');
        $this->assertFalse($documents->available($invoice, $settings));
        $this->assertTrue($documents->available($invoice, $settings, true));
        $this->get($publicDocument)->assertNotFound();
        $this->get(route('customer-portal.preview.document', ['order' => $invoice->order_id, 'invoice' => $invoice->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertNothingSent();
    }

    public function test_settings_validate_revision_status_mapping_and_reject_hidden_fields(): void
    {
        $payload = $this->settingsPayload();
        $this->put(route('customer-portal.settings.update'), $payload)->assertRedirect();
        $this->assertFalse(PortalSetting::query()->sole()->is_enabled);
        $this->put(route('customer-portal.settings.update'), $payload)->assertSessionHasErrors('expected_revision');
        $payload['expected_revision'] = 1;
        $payload['accent_color'] = '#fff; background:url(https://evil.example)';
        $this->put(route('customer-portal.settings.update'), $payload)->assertSessionHasErrors('accent_color');
        $payload['accent_color'] = '#0075c9';
        $payload['statuses'] = [['id' => 99999, 'is_visible' => 1, 'public_name' => 'bad']];
        $this->put(route('customer-portal.settings.update'), $payload)->assertSessionHasErrors('statuses');
        $this->assertSame(1, PortalSetting::query()->sole()->revision);
    }

    public function test_email_preview_is_read_only_and_preparation_freezes_the_actual_customer_link(): void
    {
        $this->settings();
        $order = $this->order();
        $account = EmailAccount::query()->create(['name' => 'Test', 'email' => 'shop@example.test', 'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587, 'smtp_username' => 'shop@example.test', 'smtp_password' => 'test', 'encryption' => EmailAccountEncryption::StartTls]);
        $template = EmailTemplate::query()->create(['email_account_id' => $account->id, 'name' => 'Portal', 'subject_template' => 'Zamówienie [id_zamowienia]',
            'body_template' => '<a href="[link_zamowienia]">Zamówienie</a>', 'format' => EmailTemplateFormat::Html, 'is_hidden' => false]);
        $preview = $this->getJson(route('orders.email-templates.preview', [$order, $template]))->assertOk()->json();
        $this->assertDatabaseCount('customer_portal_links', 1);
        $this->assertStringContainsString($this->url($order), $preview['body']);
        $data = new OrderEmailPreparationData(toEmail: $order->customer_email, toName: 'Klient', source: OrderEmailMessageSource::Manual,
            idempotencyKey: (string) Str::uuid(), emailAccount: $account, actorName: 'Operator',
            subjectTemplate: $preview['subject'], bodyTemplate: $preview['body'], format: EmailTemplateFormat::Html, contentIsRendered: true);
        $message = app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, $data);
        $this->assertDatabaseCount('customer_portal_links', 1);
        $this->assertStringContainsString($this->url($order), $message->body_html);
        $this->assertStringNotContainsString('[link_zamowienia]', $message->body_html);
        $again = app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, $data);
        $this->assertSame($message->id, $again->id);
        $this->assertDatabaseCount('order_email_messages', 1);
        Http::assertNothingSent();
    }

    public function test_email_variable_is_read_only_and_disabled_portal_is_rejected(): void
    {
        $this->settings();
        $order = $this->order();
        $this->assertSame($this->url($order), app(OrderVariableService::class)->render('[link_zamowienia]', $order));
        $this->assertDatabaseCount('customer_portal_links', 1);
        PortalSetting::query()->update(['is_enabled' => false]);
        $this->expectException(OrderEmailException::class);
        app(OrderVariableService::class)->render('[link_zamowienia]', $order);
    }

    public function test_post_actions_require_csrf_and_explicit_confirmation(): void
    {
        $this->settings();
        $order = $this->order();
        $this->postJson(route('customer-portal.issue', $order))->assertUnprocessable();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post(route('customer-portal.issue', $order), ['confirm' => 1])->assertStatus(419);
        $this->assertDatabaseCount('customer_portal_links', 1);
    }

    public function test_proforma_is_opt_in_and_disappears_when_superseded_by_invoice(): void
    {
        $settings = $this->settings();
        $order = $this->order();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order, $this->createDocumentSeries(InvoiceDocumentType::Proforma), $this->documentContext()
        )->invoice;
        $documents = app(PortalDocumentService::class);
        $this->assertFalse($documents->available($proforma, $settings));
        $settings->update(['show_proformas' => true]);
        $this->assertTrue($documents->available($proforma, $settings));
        $this->invoice($order);
        $this->assertFalse($documents->available($proforma->fresh(), $settings));
    }

    public function test_email_failure_rolls_back_new_link_and_stale_preview_or_wrong_recipient_is_rejected(): void
    {
        $this->settings();
        $order = Order::withoutEvents(fn () => $this->order());
        $account = EmailAccount::query()->create(['name' => 'Test', 'email' => 'shop@example.test', 'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587, 'smtp_username' => 'shop@example.test', 'smtp_password' => 'test', 'encryption' => EmailAccountEncryption::StartTls]);
        $template = EmailTemplate::query()->create(['email_account_id' => $account->id, 'name' => 'Portal', 'subject_template' => 'Zamówienie',
            'body_template' => '[link_zamowienia]', 'format' => EmailTemplateFormat::PlainText, 'is_hidden' => false]);
        $service = app(OrderEmailPreparationService::class);
        $data = fn (string $subject, string $body, ?string $recipient = null) => new OrderEmailPreparationData(
            toEmail: $recipient ?? $order->customer_email, toName: 'Klient', source: OrderEmailMessageSource::Manual,
            idempotencyKey: (string) Str::uuid(), emailAccount: $account, actorName: 'Operator',
            subjectTemplate: $subject, bodyTemplate: $body, format: EmailTemplateFormat::PlainText, contentIsRendered: true
        );
        try {
            $service->prepareAndQueue($order, $template, $data('', '[link_zamowienia]'));
            $this->fail('An invalid message must roll back the link.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('subject_invalid', $exception->safeCode());
        }
        $this->assertDatabaseCount('customer_portal_links', 0);
        $this->assertDatabaseCount('order_email_messages', 0);
        try {
            $service->prepareAndQueue($order, $template, $data('Zamówienie', '[link_zamowienia]', 'foreign@example.test'));
            $this->fail('Customer access must not be issued to another recipient.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('portal_recipient_mismatch', $exception->safeCode());
        }
        $this->assertDatabaseCount('customer_portal_links', 0);
        Order::query()->whereKey($order->id)->update(['customer_email' => 'new-buyer@example.test']);
        try {
            $service->prepareAndQueue($order, $template, $data('Zamówienie', '[link_zamowienia]'));
            $this->fail('A stale order must not issue current buyer access to the previous recipient.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('portal_unavailable', $exception->safeCode());
        }
        $this->assertDatabaseCount('customer_portal_links', 0);
        $order->refresh();
        $oldUrl = $this->url($order);
        app(PortalAccessService::class)->issue($order, true, 1);
        try {
            $service->prepareAndQueue($order, $template, $data('Zamówienie', $oldUrl));
            $this->fail('A rotated link in an old preview must not be sent.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('portal_preview_expired', $exception->safeCode());
        }
        $this->assertDatabaseCount('order_email_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_old_orders_and_expired_links_cannot_be_reactivated_by_copy_or_policy_extension(): void
    {
        $settings = $this->settings();
        $order = $this->order();
        $url = $this->url($order);
        $this->travel(91)->days();
        $settings->update(['availability_days' => 365]);
        $this->get($url)->assertNotFound();
        $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1])->assertUnprocessable();
        $new = $this->postJson(route('customer-portal.issue', $order), ['confirm' => 1, 'rotate' => 1, 'expected_generation' => 1])->assertOk()->json('url');
        $this->get($new)->assertOk();
        $this->get($url)->assertNotFound();
        $old = $this->order(['purchased_at' => now()->subDays(366)]);
        $this->postJson(route('customer-portal.issue', $old), ['confirm' => 1])->assertUnprocessable();
    }

    public function test_deleted_status_does_not_publish_internal_fallback_and_deleted_order_releases_only_nullable_relation(): void
    {
        $this->settings();
        $order = $this->order(['status' => 'internal-custom']);
        $status = OrderStatusSetting::query()->create(['status' => 'internal-custom', 'short_name' => 'Internal', 'full_name' => 'Internal', 'color' => '#123456']);
        PortalStatus::query()->create(['order_status_setting_id' => $status->id, 'is_visible' => true, 'public_name' => 'Public stage']);
        $url = $this->url($order);
        $this->get($url)->assertSeeText('Public stage');
        $status->delete();
        $this->get($url)->assertDontSeeText('Public stage')->assertDontSeeText('Internal');
        $order->forceDelete();
        $this->assertNull(PortalLink::query()->sole()->order_id);
        $this->get($url)->assertNotFound();
        $migration = require database_path('migrations/2026_10_26_040000_create_customer_portal_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_migration_mysql_constraints_are_short_and_do_not_delete_document_history(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new class(fn () => throw new \RuntimeException('No live MySQL allowed.'), 'schema_preview') extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.46';
            }
        };
        Schema::swap($connection->getSchemaBuilder());
        try {
            $migration = require database_path('migrations/2026_10_26_040000_create_customer_portal_tables.php');
            $sql = implode("\n", array_column($connection->pretend(fn () => $migration->up()), 'query'));
        } finally {
            Schema::swap($original);
        }
        $this->assertStringContainsString('`portal_link_order_fk`', $sql);
        $this->assertStringContainsString('`portal_status_setting_fk`', $sql);
        $this->assertSame(2, substr_count($sql, 'on delete set null'));
        preg_match_all('/(?:constraint|unique|index) `([^`]+)`/', $sql, $names);
        foreach ($names[1] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    public function test_public_routes_reject_post_and_enforce_rate_limit(): void
    {
        $this->settings();
        $url = $this->url($this->order());
        $this->post($url)->assertMethodNotAllowed();
        for ($i = 0; $i < 180; $i++) {
            $this->get($url)->assertOk();
        }
        $this->get($url)->assertTooManyRequests();
        Http::assertNothingSent();
    }

    public function test_offline_without_matching_acceptance_is_never_published_as_standard_pdf(): void
    {
        $settings = $this->settings();
        $invoice = $this->invoice($this->order());
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Production]);
        $issuanceId = DB::table('ksef_offline_issuances')->insertGetId([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'procedure' => 'offline24',
            'issue_date' => now()->toDateString(), 'issued_at' => now(), 'seller_nip' => '9876543210',
            'context_identifier_type' => 'Nip', 'context_identifier_value' => '9876543210', 'schema_id' => 'FA(3)',
            'payload_xml' => encrypt('<test/>'), 'invoice_hash' => base64_encode(hash('sha256', '<test/>', true)), 'invoice_size' => 7,
            'certificate_serial_number' => 'TEST', 'certificate_fingerprint_sha256' => str_repeat('a', 64),
            'certificate_valid_from' => now()->subDay(), 'certificate_valid_until' => now()->addDay(),
            'certificate_remote_status' => 'Active', 'certificate_remote_valid_from' => now()->subDay(),
            'certificate_remote_valid_until' => now()->addDay(), 'certificate_remote_verified_at' => now(),
            'invoice_verification_url' => 'https://example.test/invoice', 'certificate_verification_url' => 'https://example.test/certificate',
        ]);
        $documents = app(PortalDocumentService::class);
        $this->assertFalse($documents->available($invoice, $settings));
        $submission = $this->submission($invoice, KsefEnvironment::Production, 'accepted');
        $this->assertFalse($documents->available($invoice, $settings));
        $this->get($this->portalRoute('customer-portal.document', ['token' => basename($this->url($invoice->order)), 'invoice' => $invoice->id]))->assertNotFound();
        $this->assertSame(1, DB::table('ksef_offline_issuances')->where('id', $issuanceId)->count());
        Http::assertNothingSent();
    }

    public function test_missing_migration_and_unrelated_pay_token_never_grant_access(): void
    {
        $this->settings();
        $url = $this->url($this->order());
        $this->get('/pay/'.basename($url))->assertNotFound();
        Schema::drop('customer_portal_links');
        $this->get($url)->assertNotFound();
        $this->get(route('customer-portal.settings'))->assertOk()->assertSeeText('Przed konfiguracją wykonaj migrację');
        Http::assertNothingSent();
    }

    public function test_settings_only_accept_allowed_fields_and_validate_visible_status_name(): void
    {
        $before = $this->order()->fresh()->getAttributes();
        $payload = $this->settingsPayload() + ['logo_path' => 'invoices/secret.pdf', 'paid_amount' => '9999.00', 'id' => 55];
        $payload['expected_revision'] = 1;
        $this->put(route('customer-portal.settings.update'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(PortalSetting::query()->sole()->logo_path);
        $this->assertSame($before, Order::query()->sole()->getAttributes());
        $status = OrderStatusSetting::query()->create(['status' => 'new', 'short_name' => 'Nowe', 'full_name' => 'Nowe', 'color' => '#123456']);
        $payload['expected_revision'] = 2;
        $payload['statuses'] = [['id' => $status->id, 'is_visible' => 1, 'public_name' => '']];
        $this->put(route('customer-portal.settings.update'), $payload)->assertSessionHasErrors('statuses');
        $this->assertDatabaseCount('customer_portal_statuses', 0);
        $this->assertSame(2, PortalSetting::query()->sole()->revision);
    }

    private function settings(array $attributes = []): PortalSetting
    {
        return PortalSetting::query()->create($attributes + ['is_enabled' => true, 'store_name' => 'Multi-Click', 'mask_personal_data' => false, 'verify_documents' => false]);
    }

    private function order(array $attributes = []): Order
    {
        return $this->createDocumentOrder($attributes + ['purchased_at' => now(), 'notes' => 'INTERNAL-SECRET'])->fresh();
    }

    private function url(Order $order): string
    {
        return app(PortalAccessService::class)->issue($order);
    }

    private function invoice(Order $order): Invoice
    {
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext(now()->format('Y-m-d H:i:s')));
    }

    private function submission(Invoice $invoice, KsefEnvironment $environment, string $status): KsefInvoiceSubmission
    {
        return KsefInvoiceSubmission::query()->create(['invoice_id' => $invoice->id, 'environment' => $environment,
            'attempt_number' => 1, 'status' => $status, 'schema_id' => 'FA(3)', 'generated_at' => now(),
            'payload_xml' => '<test/>', 'invoice_hash' => base64_encode(hash('sha256', '<test/>', true)), 'invoice_size' => 7,
            'seller_nip' => '9876543210', 'context_nip' => '9876543210', 'ksef_number' => $status === 'accepted' ? KsefUpoFixture::ksefNumber('9876543210') : null,
            'acquisition_date' => $status === 'accepted' ? now() : null, 'invoicing_mode' => $status === 'accepted' ? 'Online' : null]);
    }

    private function settingsPayload(): array
    {
        return ['expected_revision' => 0, 'is_enabled' => 0, 'store_name' => 'Multi-Click', 'accent_color' => '#0075c9',
            'availability_days' => 90, 'show_images' => 1, 'show_address' => 0, 'show_invoices' => 1, 'show_corrections' => 1, 'show_proformas' => 0];
    }
}
