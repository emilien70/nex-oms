<?php

namespace Tests\Feature;

use App\Models\OrderStatusSetting;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\ExportAllegroInvoiceJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportService;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportView;
use Modules\Integrations\Allegro\Services\AllegroInvoiceSettingsService;
use Modules\Invoices\Events\InvoiceIssued;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class AllegroInvoiceExportTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    private const REMOTE_ID = '56ae349d-8045-4bb3-adcc-7cf6fb420f61';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->mock(InvoicePdfRenderer::class)->shouldReceive('render')->andReturn('%PDF-1.7 private-invoice-content');
    }

    protected function tearDown(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
        parent::tearDown();
    }

    public function test_automatic_issue_creates_outbox_and_disabled_mode_does_nothing(): void
    {
        [$invoice, $account] = $this->invoice('disabled');
        $this->assertDatabaseCount('allegro_invoice_exports', 0);
        $account->update(['invoice_sync_mode' => 'immediate']);
        InvoiceIssued::dispatch($invoice, 'manual');
        InvoiceIssued::dispatch($invoice, 'manual');
        $this->assertDatabaseCount('allegro_invoice_exports', 1);
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_rollback_does_not_dispatch_invoice_export(): void
    {
        [$invoice] = $this->invoice('disabled');
        DB::beginTransaction();
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        DB::rollBack();
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        $this->assertDatabaseMissing('allegro_invoice_exports', ['id' => $export->id]);
    }

    public function test_upload_is_multipart_private_and_not_confirmed_until_security_accepted(): void
    {
        [$invoice] = $this->invoice();
        $remote = [];
        $this->fakeApi($remote);
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $this->assertNull($export->fresh()->confirmed_at);
        Storage::disk('local')->assertExists($export->fresh()->pdf_path);
        $this->assertSame(hash('sha256', '%PDF-1.7 private-invoice-content'), $export->fresh()->pdf_checksum);
        Http::assertSent(function (Request $request) use ($invoice): bool {
            return $request->method() === 'POST' && str_ends_with($request->url(), '/billing-documents/files')
                && str_contains($request->body(), 'private-invoice-content') && str_contains($request->body(), $invoice->number)
                && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data');
        });
        $remote = [$this->remote($export->fresh(), 'WAITING')];
        $this->check($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertNotNull($export->fresh()->confirmed_at);
        $this->assertSame(1, $invoice->order->events()->where('event_type', 'allegro_invoice_confirmed')->count());
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        $this->assertStringNotContainsString('private-invoice-content', DB::table('integration_api_logs')->get()->toJson());
    }

    public function test_status_gate_works_in_both_orders(): void
    {
        [$invoice] = $this->invoice('status');
        $export = AllegroInvoiceExport::query()->sole();
        $this->assertSame('waiting', $export->state);
        app(OrderStatusService::class)->change($invoice->order, 'pending', 'manual');
        $this->assertSame('pending', $export->fresh()->state);
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 1);
        [$second] = $this->invoice('status', 'pending');
        $this->assertDatabaseHas('allegro_invoice_exports', ['invoice_id' => $second->id, 'state' => 'pending']);
    }

    public function test_leaving_status_gate_while_queued_prevents_http(): void
    {
        [$invoice] = $this->invoice('status', 'pending');
        $export = AllegroInvoiceExport::query()->sole();
        app(OrderStatusService::class)->change($invoice->order, 'new', 'manual');
        $this->runExport($export);
        $this->assertSame('waiting', $export->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_only_requested_filter_and_manual_override_when_disabled(): void
    {
        [$invoice, $account, $link] = $this->invoice('disabled');
        $account->update(['invoice_sync_mode' => 'immediate', 'invoice_sync_only_required' => true]);
        $link->update(['invoice_required' => false]);
        InvoiceIssued::dispatch($invoice, 'manual');
        $this->assertDatabaseCount('allegro_invoice_exports', 0);
        $account->update(['invoice_sync_mode' => 'disabled']);
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        $this->assertTrue($export->manual);
        $this->assertSame('pending', $export->state);
    }

    public function test_manual_endpoint_requires_confirmation_and_current_version(): void
    {
        [$invoice] = $this->invoice('disabled');
        $url = route('orders.allegro.invoices.store', ['order' => $invoice->order_id, 'invoice' => $invoice]);
        $this->post($url)->assertSessionHasErrors(['confirmed', 'expected_lock_version']);
        $this->post($url, ['confirmed' => 1, 'expected_lock_version' => 999])->assertSessionHasErrors('allegro_invoice_export');
        $this->post($url, ['confirmed' => 1, 'expected_lock_version' => $invoice->lock_version])->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $invoice->order));
        $this->assertDatabaseCount('allegro_invoice_exports', 1);
        $other = $this->createDocumentOrder();
        $this->post(route('orders.allegro.invoices.store', ['order' => $other, 'invoice' => $invoice]), ['confirmed' => 1, 'expected_lock_version' => $invoice->lock_version])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_incomplete_cancelled_or_foreign_source_is_never_queued(): void
    {
        [$invoice, , $link] = $this->invoice('disabled');
        foreach ([['import_state' => 'incomplete'], ['external_status' => 'CANCELLED'], ['seller_user_id' => 'other'], ['environment' => 'sandbox']] as $change) {
            $old = $link->getAttributes();
            $link->update($change);
            $this->assertNull(app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true));
            $link->forceFill($old)->save();
        }
        $invoice->order->update(['status' => 'cancelled']);
        $this->assertNull(app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true));
        Http::assertNothingSent();
    }

    public function test_old_configuration_and_identity_are_checked_before_any_http(): void
    {
        [, $account] = $this->invoice();
        $export = AllegroInvoiceExport::query()->sole();
        app(AllegroInvoiceSettingsService::class)->update(['identity' => $account->orderImportIdentity(), 'configuration_revision' => 1,
            'invoice_sync_mode' => 'disabled', 'invoice_sync_only_required' => 0]);
        $this->runExport($export);
        $this->assertSame('skipped', $export->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_existing_or_malformed_remote_list_never_causes_an_upload(): void
    {
        $this->invoice();
        $export = AllegroInvoiceExport::query()->sole();
        $remote = [['id' => self::REMOTE_ID, 'invoiceNumber' => 'foreign']];
        $this->fakeApi($remote);
        $this->runExport($export);
        $this->assertSame('failed', $export->fresh()->state);
        $this->assertNull($export->fresh()->upload_started_at);
        app(AllegroInvoiceExportService::class)->retry($export->id);
        $remote = [null];
        try {
            $this->runExport($export->fresh());
            $this->fail('Malformed responses must not be treated as empty lists.');
        } catch (AllegroApiException $exception) {
            $this->assertSame(502, $exception->statusCode);
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_ambiguous_upload_is_only_reconciled_and_never_blindly_repeated(): void
    {
        $this->invoice();
        $remote = [];
        $posts = 0;
        Http::fake(function (Request $request) use (&$remote, &$posts) {
            if ($request->method() === 'POST') {
                $posts++;
                throw new ConnectionException('private transport details');
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => $remote] : Fixture::payload());
        });
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('uncertain', $export->fresh()->state);
        $this->check($export);
        $this->assertSame(1, $posts);
        $this->assertSame('uncertain', $export->fresh()->state);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame(1, $posts);
    }

    public function test_same_number_without_our_filename_is_not_a_confirmation(): void
    {
        $this->invoice();
        $remote = [];
        $this->fakeApi($remote);
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $remote = [$this->remote($export->fresh())];
        $remote[0]['file']['name'] = 'foreign.pdf';
        $this->check($export);
        $this->assertSame('uncertain', $export->fresh()->state);
        $this->assertNull($export->fresh()->confirmed_at);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_security_rejection_does_not_trigger_another_upload(): void
    {
        $this->invoice();
        $remote = [];
        $this->fakeApi($remote);
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $remote = [$this->remote($export->fresh(), 'REJECTED')];
        $this->check($export);
        $this->assertSame('failed', $export->fresh()->state);
        $this->check($export);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_changed_document_warns_and_cannot_be_reuploaded(): void
    {
        [$invoice] = $this->invoice();
        $remote = [];
        $this->fakeApi($remote);
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $invoice->update(['additional_information_text' => 'changed']);
        $view = app(AllegroInvoiceExportView::class)->data($invoice->order, $invoice->fresh());
        $this->assertTrue($view['changed']);
        $this->assertFalse($view['canSend']);
        $this->assertTrue($view['canRetry']);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_historical_export_and_private_copy_survive_invoice_and_account_deletion(): void
    {
        [$invoice, $account] = $this->invoice();
        $remote = [];
        $this->fakeApi($remote);
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $number = $invoice->number;
        $invoice->delete();
        $account->delete();
        $export->refresh();
        $this->assertNull($export->invoice_id);
        $this->assertNull($export->allegro_account_id);
        $this->assertSame($number, $export->invoice_number);
        $this->assertSame('synced', $export->state);
        Storage::disk('local')->assertExists($export->pdf_path);
        $this->assertDatabaseHas('order_events', ['event_type' => 'allegro_invoice_confirmed']);
    }

    public function test_ksef_series_waits_without_sending_preview_or_test_document_to_production(): void
    {
        [$invoice] = $this->invoice('disabled');
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Test]);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $invoice->invoice_series_id, 'is_enabled' => true]);
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        $this->assertSame('waiting', $export->state);
        $this->assertStringContainsString('produkcyjny', $export->error);
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        Http::assertNothingSent();
    }

    public function test_job_recovery_resumes_known_intent_read_only_and_does_not_scan_old_invoices(): void
    {
        [$invoice] = $this->invoice('disabled');
        app(AllegroInvoiceExportService::class)->recover();
        $this->assertDatabaseCount('allegro_invoice_exports', 0);
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        $export->update(['state' => 'uploading', 'upload_started_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);
        Queue::fake();
        app(AllegroInvoiceExportService::class)->recover();
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 1);
        $remote = [];
        $this->fakeApi($remote);
        $this->runExport($export->fresh());
        $this->assertSame('uncertain', $export->fresh()->state);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_multipart_upload_and_user_agent_survive_oauth_refresh_after_401(): void
    {
        [, $account] = $this->invoice();
        $posts = 0;
        Http::fake(function (Request $request) use (&$posts, $account) {
            if ($request->url() === $account->authUrl()) {
                return Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]);
            }
            if (str_ends_with($request->url(), '/billing-documents/files')) {
                $posts++;
                $this->assertStringContainsString('private-invoice-content', $request->body());

                return $posts === 1 ? Http::response([], 401) : Http::response(['id' => self::REMOTE_ID], 201);
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => []] : Fixture::payload());
        });
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $this->assertSame(2, $posts);
        $this->assertSame('new-access', $account->fresh()->access_token);
        Http::assertSentCount(5);
    }

    public function test_post_503_is_uncertain_and_manual_check_does_not_repeat_post(): void
    {
        $this->invoice();
        Http::fake(fn (Request $request) => $request->method() === 'POST' ? Http::response([], 503)
            : Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => []] : Fixture::payload()));
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('uncertain', $export->fresh()->state);
        $this->check($export);
        $this->assertSame('uncertain', $export->fresh()->state);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_definite_rate_limit_can_retry_the_exact_frozen_pdf(): void
    {
        $this->invoice();
        $code = 429;
        Http::fake(function (Request $request) use (&$code) {
            return $request->method() === 'POST' ? Http::response(['id' => self::REMOTE_ID], $code)
                : Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => []] : Fixture::payload());
        });
        $export = AllegroInvoiceExport::query()->sole();
        try {
            $this->runExport($export);
            $this->fail('Rate limit must allow queue backoff.');
        } catch (AllegroApiException $exception) {
            $this->assertSame(429, $exception->statusCode);
        }
        $export->refresh();
        $this->assertNull($export->upload_started_at);
        $checksum = $export->pdf_checksum;
        $filename = $export->file_name;
        $code = 201;
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $this->assertSame($checksum, $export->fresh()->pdf_checksum);
        $this->assertSame($filename, $export->fresh()->file_name);
        $this->assertSame(2, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_acceptance_in_ksef_wakes_only_known_waiting_invoice_once(): void
    {
        [$invoice] = $this->invoice('disabled');
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Production]);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $invoice->invoice_series_id, 'is_enabled' => true]);
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        $this->assertSame('waiting', $export->state);
        $xml = '<Faktura>TEST</Faktura>';
        $submission = KsefInvoiceSubmission::query()->create(['invoice_id' => $invoice->id, 'environment' => KsefEnvironment::Production,
            'context_nip' => KsefUpoFixture::SELLER_NIP, 'seller_nip' => KsefUpoFixture::SELLER_NIP, 'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Accepted, 'invoicing_mode' => KsefInvoicingMode::Online,
            'schema_id' => 'FA (3) 1-0E', 'generated_at' => now(), 'payload_xml' => $xml,
            'invoice_hash' => base64_encode(hash('sha256', $xml, true)), 'invoice_size' => strlen($xml),
            'ksef_number' => KsefUpoFixture::ksefNumber(), 'acquisition_date' => now()]);
        KsefInvoiceAccepted::dispatch($submission);
        KsefInvoiceAccepted::dispatch($submission);
        $this->assertSame('pending', $export->fresh()->state);
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_configuration_change_during_preflight_stops_the_upload(): void
    {
        [, $account] = $this->invoice();
        Http::fake(function (Request $request) use ($account) {
            if (str_ends_with($request->url(), '/invoices')) {
                $account->update(['invoice_sync_revision' => 2]);

                return Http::response(['invoices' => []]);
            }

            return Http::response(Fixture::payload());
        });
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('skipped', $export->fresh()->state);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_late_upload_acknowledgement_is_preserved_after_disabling_automatic_mode(): void
    {
        [, $account] = $this->invoice();
        $remote = [];
        Http::fake(function (Request $request) use (&$remote, $account) {
            if ($request->method() === 'POST') {
                app(AllegroInvoiceSettingsService::class)->update(['identity' => $account->orderImportIdentity(), 'configuration_revision' => 1,
                    'invoice_sync_mode' => 'disabled', 'invoice_sync_only_required' => 0]);

                return Http::response(['id' => self::REMOTE_ID], 201);
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => $remote] : Fixture::payload());
        });
        $export = AllegroInvoiceExport::query()->sole();
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_order_fragment_shows_manual_action_and_changed_document_warning(): void
    {
        [$invoice] = $this->invoice('disabled');
        $html = app(OrderSalesDocumentActionsView::class)->render($invoice->order);
        $this->assertStringContainsString('data-allegro-invoice-export', $html);
        $this->assertStringContainsString('Przekaż do Allegro', $html);
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        $remote = [];
        $this->fakeApi($remote);
        $this->runExport($export);
        $invoice->update(['additional_information_text' => 'Changed after upload']);
        $html = app(OrderSalesDocumentActionsView::class)->render($invoice->order->fresh());
        $this->assertStringContainsString('Faktura zmieniła się', $html);
        $this->assertStringContainsString('Sprawdź w Allegro', $html);
    }

    public function test_manual_request_from_before_reconnection_cannot_upload_automatically_later(): void
    {
        [$invoice, $account] = $this->invoice('disabled');
        $export = app(AllegroInvoiceExportService::class)->enqueue($invoice->id, true);
        app(AllegroAccountService::class)->disconnect($account);
        $account->refresh()->forceFill(['access_token' => 'access', 'refresh_token' => 'refresh', 'allegro_user_id' => 'seller-1',
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'last_error' => null, 'last_test_status' => AllegroAccount::STATUS_SUCCESS, 'is_active' => true])->save();
        $this->runExport($export);
        $this->assertSame('skipped', $export->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_new_status_change_can_reactivate_a_never_uploaded_request_after_configuration_change(): void
    {
        [$invoice, $account] = $this->invoice();
        $export = AllegroInvoiceExport::query()->sole();
        app(AllegroInvoiceSettingsService::class)->update(['identity' => $account->orderImportIdentity(), 'configuration_revision' => 1,
            'invoice_sync_mode' => 'status', 'invoice_sync_only_required' => 0,
            'invoice_sync_status_setting_id' => OrderStatusSetting::query()->where('status', 'pending')->value('id')]);
        $this->assertSame('skipped', $export->fresh()->state);
        Queue::fake();
        app(OrderStatusService::class)->change($invoice->order, 'pending', 'manual');
        $this->assertSame('pending', $export->fresh()->state);
        $this->assertSame(2, $export->fresh()->configuration_revision);
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 1);
        Http::assertNothingSent();
    }

    private function invoice(string $mode = 'immediate', string $status = 'new'): array
    {
        OrderStatusSetting::syncDefaults();
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill(['client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1', 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS, 'invoice_sync_mode' => $mode,
            'invoice_sync_identity' => 'production:seller-1', 'invoice_sync_revision' => 1,
            'invoice_sync_status_setting_id' => OrderStatusSetting::query()->where('status', 'pending')->value('id')])->save();
        $order = $this->createDocumentOrder(['source' => 'allegro', 'external_id' => Fixture::ORDER_ID, 'status' => $status]);
        $externalId = AllegroOrderLink::query()->exists() ? (string) Str::uuid() : Fixture::ORDER_ID;
        $link = AllegroOrderLink::query()->create(['allegro_account_id' => $account->id, 'order_id' => $order->id,
            'environment' => 'production', 'seller_user_id' => 'seller-1', 'account_name' => 'multi-click', 'external_order_id' => $externalId,
            'external_status' => 'READY_FOR_PROCESSING', 'fulfillment_status' => 'NEW', 'source_hash' => hash('sha256', $externalId),
            'imported_at' => now(), 'import_state' => 'complete', 'invoice_required' => true]);
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());

        return [$invoice, $account->fresh(), $link];
    }

    private function fakeApi(array &$remote): void
    {
        Http::fake(function (Request $request) use (&$remote) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => self::REMOTE_ID], 201);
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => $remote] : Fixture::payload());
        });
    }

    private function remote(AllegroInvoiceExport $export, string $security = 'ACCEPTED'): array
    {
        return ['id' => self::REMOTE_ID, 'invoiceNumber' => $export->invoice_number, 'file' => ['name' => $export->file_name,
            'uploadedAt' => now()->toIso8601String(), 'securityVerification' => ['status' => $security]]];
    }

    private function runExport(AllegroInvoiceExport $export): void
    {
        (new ExportAllegroInvoiceJob($export->id, $export->attempt_token))->handle(app(AllegroInvoiceExportService::class));
    }

    private function check(AllegroInvoiceExport $export): void
    {
        $this->assertTrue(app(AllegroInvoiceExportService::class)->retry($export->id));
        $this->runExport($export->fresh());
    }
}
