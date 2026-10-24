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
use Modules\Integrations\Allegro\Jobs\ExportAllegroInvoiceJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroAccountService;
use Modules\Integrations\Allegro\Services\AllegroInvoiceDocumentService;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportService;
use Modules\Integrations\Allegro\Services\AllegroInvoiceExportView;
use Modules\Integrations\Allegro\Services\AllegroInvoiceSettingsService;
use Modules\Invoices\Events\CorrectionFinalized;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Models\KsefSeriesSetting;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

class AllegroCorrectionExportTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    private const REMOTE_ID = '56ae349d-8045-4bb3-adcc-7cf6fb420f61';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->mock(InvoicePdfRenderer::class)->shouldReceive('render')->andReturn('%PDF-1.7 correction-content');
    }

    protected function tearDown(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
        }
        parent::tearDown();
    }

    public function test_numbered_editable_correction_is_not_exported_and_finalization_is_idempotent(): void
    {
        [$root] = $this->source();
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->assertNull(app(AllegroInvoiceExportService::class)->enqueue($correction->id, true));
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        $before = $correction->getAttributes();
        app(InvoiceFinalizationService::class)->finalize($correction);
        app(InvoiceFinalizationService::class)->finalize($correction->fresh());
        CorrectionFinalized::dispatch($correction->fresh());
        $export = $this->correctionExport();
        $this->assertSame('pending', $export->state);
        $this->assertSame($root->number, $export->source_invoice_number);
        $this->assertSame($before['lock_version'], $correction->fresh()->lock_version);
        $this->assertSame($before['number'], $correction->fresh()->number);
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_rollback_of_finalization_does_not_emit_or_queue_export(): void
    {
        [$root] = $this->source();
        $correction = $this->issueKsefFinancialCorrection($root);
        DB::beginTransaction();
        try {
            app(InvoiceFinalizationService::class)->finalize($correction);
            Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        } finally {
            DB::rollBack();
        }
        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertSame(0, AllegroInvoiceExport::query()->where('document_type', 'correction')->count());
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
    }

    public function test_disabled_mode_and_settings_save_never_backfill_closed_corrections(): void
    {
        [$root, $account] = $this->source('disabled');
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $this->saveSettings($account, 'immediate');
        app(AllegroInvoiceExportService::class)->recover();
        $this->assertSame(0, AllegroInvoiceExport::query()->where('document_type', 'correction')->count());
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        $this->assertNotNull(app(AllegroInvoiceExportService::class)->enqueue($correction->id, true));
        Http::assertNothingSent();
    }

    public function test_status_gate_works_in_both_orders_and_never_exports_an_open_correction(): void
    {
        [$root] = $this->source('status');
        $first = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        $this->assertSame('waiting', $export->state);
        app(OrderStatusService::class)->change($root->order, 'pending', 'manual');
        $this->assertSame('pending', $export->fresh()->state);
        $second = $this->issueKsefFinancialCorrection($root, 3);
        app(AllegroInvoiceExportService::class)->statusChanged($root->order_id);
        $this->assertDatabaseMissing('allegro_invoice_exports', ['invoice_id' => $second->id]);
        $this->finalizeKsefCorrection($second);
        $this->assertDatabaseHas('allegro_invoice_exports', ['invoice_id' => $second->id, 'state' => 'pending']);
        $this->assertNotSame($first->id, $second->id);
        Queue::assertPushed(ExportAllegroInvoiceJob::class, 2);
    }

    public function test_leaving_selected_status_prevents_http_and_only_required_filter_does_not_apply(): void
    {
        [$root, $account, $link] = $this->source('status');
        $account->update(['invoice_sync_only_required' => true]);
        $link->update(['invoice_required' => false]);
        app(OrderStatusService::class)->change($root->order, 'pending', 'manual');
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        $this->assertSame('pending', $export->state);
        app(OrderStatusService::class)->change($root->order->fresh(), 'new', 'manual');
        $this->runExport($export);
        $this->assertSame('waiting', $export->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_existing_original_and_earlier_corrections_are_not_replaced_or_adopted(): void
    {
        [$root] = $this->source();
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        $remote = [['id' => (string) Str::uuid(), 'invoiceNumber' => $root->number],
            ['id' => (string) Str::uuid(), 'invoiceNumber' => 'old correction']];
        $this->fakeApi($remote);
        $before = $correction->getAttributes();
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $this->assertSame($before, $correction->fresh()->getAttributes());
        $remote[] = $this->remote($export->fresh());
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->body(), $correction->number) && str_contains($request->body(), 'correction-content'));
        $this->assertSame(1, $root->order->events()->where('event_type', 'allegro_correction_confirmed')->count());
    }

    public function test_each_closed_correction_has_its_own_artifact_and_export(): void
    {
        [$root] = $this->source();
        $first = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $second = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root, 3));
        $remote = [];
        $this->fakeApi($remote);
        $exports = AllegroInvoiceExport::query()->where('document_type', 'correction')->get();
        foreach ($exports as $export) {
            $this->runExport($export);
        }
        $this->assertCount(2, $exports);
        $this->assertNotSame($exports[0]->fresh()->file_name, $exports[1]->fresh()->file_name);
        $this->assertNotSame($exports[0]->fresh()->pdf_path, $exports[1]->fresh()->pdf_path);
        $this->assertSame([$first->id, $second->id], $exports->pluck('invoice_id')->all());
    }

    public function test_missing_original_confirmation_waits_and_manual_override_does_not_send_original(): void
    {
        [$root] = $this->source(confirmed: false);
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        $this->assertSame('waiting', $export->state);
        Queue::assertNotPushed(ExportAllegroInvoiceJob::class);
        $view = app(AllegroInvoiceExportView::class)->data($root->order, $correction);
        $this->assertTrue($view['sourceWarning']);
        $this->assertTrue($view['canRetry']);
        $this->post(route('orders.allegro.invoices.retry', ['order' => $root->order, 'export' => $export]), ['confirmed' => 1])
            ->assertSessionHasNoErrors();
        $this->assertTrue($export->fresh()->manual);
        $this->assertSame('pending', $export->fresh()->state);
        $this->assertDatabaseMissing('allegro_invoice_exports', ['invoice_id' => $root->id]);
        Http::assertNothingSent();
    }

    public function test_manual_endpoint_requires_confirmation_version_and_closed_document(): void
    {
        [$root] = $this->source('disabled');
        $correction = $this->issueKsefFinancialCorrection($root);
        $url = route('orders.allegro.invoices.store', ['order' => $root->order, 'invoice' => $correction]);
        $this->post($url)->assertSessionHasErrors(['confirmed', 'expected_lock_version']);
        $data = ['confirmed' => 1, 'expected_lock_version' => $correction->lock_version];
        $this->post($url, $data)->assertSessionHasErrors('allegro_invoice_export');
        $this->finalizeKsefCorrection($correction);
        $this->post($url, array_replace($data, ['expected_lock_version' => 999]))->assertSessionHasErrors('allegro_invoice_export');
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->correctionExport()->state);
    }

    public function test_cancelled_order_can_export_correction_without_changing_local_status(): void
    {
        [$root, , $link] = $this->source();
        $link->update(['import_state' => 'cancelled', 'external_status' => 'CANCELLED', 'fulfillment_status' => 'CANCELLED']);
        $root->order->update(['status' => 'cancelled']);
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $remote = [];
        $this->fakeApi($remote, cancelled: true);
        $export = $this->correctionExport();
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $this->assertSame('cancelled', $root->order->fresh()->status);
    }

    public function test_invalid_import_identity_and_broken_chain_are_rejected_before_http(): void
    {
        [$root, , $link] = $this->source('disabled');
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        foreach ([['import_state' => 'incomplete'], ['import_state' => 'superseded'], ['seller_user_id' => 'other'],
            ['environment' => 'sandbox'], ['import_state' => 'cancelled', 'completed_at' => null]] as $change) {
            $old = $link->getAttributes();
            $link->update($change);
            $this->assertNull(app(AllegroInvoiceExportService::class)->enqueue($correction->id, true));
            $link->forceFill($old)->save();
        }
        $correction->update(['previous_correction_id' => $correction->id]);
        $this->assertNull(app(AllegroInvoiceExportService::class)->enqueue($correction->id, true));
        Http::assertNothingSent();
    }

    public function test_timeout_reconciliation_and_retry_never_post_a_second_copy(): void
    {
        [$root] = $this->source();
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $remote = [];
        $posts = 0;
        $this->fakeApi($remote, timeout: true, posts: $posts);
        $export = $this->correctionExport();
        $this->runExport($export);
        $this->assertSame('uncertain', $export->fresh()->state);
        $this->check($export);
        $this->assertSame('uncertain', $export->fresh()->state);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame(1, $posts);
    }

    public function test_invoice_settings_do_not_invalidate_correction_but_correction_settings_do(): void
    {
        [$root, $account] = $this->source();
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        app(AllegroInvoiceSettingsService::class)->update(['identity' => $account->orderImportIdentity(), 'configuration_revision' => 0,
            'invoice_sync_mode' => 'disabled', 'invoice_sync_only_required' => true]);
        $this->assertSame('pending', $export->fresh()->state);
        $this->saveSettings($account->fresh(), 'disabled');
        $this->runExport($export);
        $this->assertSame('skipped', $export->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_source_ksef_requirement_blocks_until_correction_accepted_in_production(): void
    {
        [$root, $account] = $this->source();
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $this->ksefSettings();
        KsefSeriesSetting::query()->create(['invoice_series_id' => $root->invoice_series_id, 'is_enabled' => true]);
        $export = $this->correctionExport();
        $this->runExport($export);
        $this->assertSame('waiting', $export->fresh()->state);
        $this->assertNotNull(app(AllegroInvoiceDocumentService::class)->waitingReason($correction, $account));
        $this->acceptKsefDocument($root);
        $this->acceptKsefDocument($correction, KsefEnvironment::Test);
        app(AllegroInvoiceExportService::class)->wakeWaiting($root->order_id);
        $this->assertSame('waiting', $export->fresh()->state);
        $accepted = $this->acceptKsefDocument($correction);
        KsefInvoiceAccepted::dispatch($accepted);
        $this->assertSame('pending', $export->fresh()->state);
        $this->assertNull(app(AllegroInvoiceDocumentService::class)->waitingReason($correction, $account));
        Http::assertNothingSent();
    }

    public function test_view_has_distinct_states_and_no_upload_for_editable_correction(): void
    {
        [$root] = $this->source('disabled', confirmed: false);
        $closed = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $open = $this->issueKsefFinancialCorrection($root, 3);
        $html = app(OrderSalesDocumentActionsView::class)->render($root->order);
        $this->assertStringContainsString('data-allegro-document-id="'.$closed->id.'"', $html);
        $this->assertStringNotContainsString('data-allegro-document-id="'.$open->id.'"', $html);
        $this->assertStringContainsString(route('orders.allegro.invoices.store', ['order' => $root->order, 'invoice' => $closed]), $html);
        $this->assertStringContainsString('Faktura Allegro:', $html);
        $this->assertGreaterThan(
            strpos($html, 'data-correction-id="'.$open->id.'"'),
            strpos($html, 'data-allegro-document-id="'.$root->id.'"'),
        );
    }

    public function test_original_confirmation_wakes_waiting_correction_without_resending_original(): void
    {
        [$root] = $this->source(confirmed: false);
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $correctionExport = $this->correctionExport();
        $this->assertSame('waiting', $correctionExport->state);
        $original = app(AllegroInvoiceExportService::class)->enqueue($root->id, true);
        $remote = [];
        $this->fakeApi($remote);
        $this->runExport($original);
        $remote[] = $this->remote($original->fresh());
        $this->check($original);
        $this->assertSame('synced', $original->fresh()->state);
        $this->assertSame('pending', $correctionExport->fresh()->state);
        $this->assertFalse($correctionExport->fresh()->manual);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_late_ack_after_disabling_corrections_is_preserved_and_only_reconciled(): void
    {
        [$root, $account] = $this->source();
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        $remote = [];
        Http::fake(function (Request $request) use ($account, &$remote) {
            if ($request->method() === 'POST') {
                $this->saveSettings($account, 'disabled');

                return Http::response(['id' => self::REMOTE_ID], 201);
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => $remote] : Fixture::payload());
        });
        $this->runExport($export);
        $this->assertSame('verifying', $export->fresh()->state);
        $this->assertSame(self::REMOTE_ID, $export->fresh()->remote_id);
        $remote = [$this->remote($export->fresh())];
        $this->check($export);
        $this->assertSame('synced', $export->fresh()->state);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
    }

    public function test_cancelled_order_api_rejection_is_visible_without_changing_order_or_source_document(): void
    {
        [$root] = $this->source();
        $root->order->update(['status' => 'cancelled']);
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $before = $correction->getAttributes();
        Http::fake(fn (Request $request) => Http::response($request->method() === 'POST'
            ? ['errors' => [['message' => 'Document limit reached', 'userMessage' => 'Przekroczono limit dokumentów.']]]
            : (str_ends_with($request->url(), '/invoices') ? ['invoices' => []] : Fixture::payload()), $request->method() === 'POST' ? 422 : 200));
        $export = $this->correctionExport();
        $this->runExport($export);
        $this->assertSame('failed', $export->fresh()->state);
        $this->assertNotEmpty($export->fresh()->error);
        $this->assertNull($export->fresh()->upload_started_at);
        $this->assertSame('cancelled', $root->order->fresh()->status);
        $this->assertSame($before, $correction->fresh()->getAttributes());
    }

    public function test_disconnected_and_reconnected_manual_request_is_not_sent_without_new_confirmation(): void
    {
        [$root, $account] = $this->source('disabled');
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = app(AllegroInvoiceExportService::class)->enqueue($correction->id, true);
        app(AllegroAccountService::class)->disconnect($account);
        $account->refresh()->forceFill(['access_token' => 'access', 'refresh_token' => 'refresh', 'allegro_user_id' => 'seller-1',
            'granted_scopes' => AllegroAccount::REQUIRED_SCOPES, 'last_test_status' => AllegroAccount::STATUS_SUCCESS, 'last_error' => null])->save();
        $this->runExport($export);
        $this->assertSame('skipped', $export->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_frozen_pdf_is_not_uploaded_if_private_copy_is_corrupted_after_rejection(): void
    {
        [$root] = $this->source();
        $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $export = $this->correctionExport();
        $posts = 0;
        Http::fake(function (Request $request) use (&$posts) {
            if ($request->method() === 'POST') {
                $posts++;

                return Http::response([], 422);
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => []] : Fixture::payload());
        });
        $this->runExport($export);
        $this->assertSame('failed', $export->fresh()->state);
        Storage::disk('local')->put($export->fresh()->pdf_path, '%PDF-tampered');
        $this->check($export);
        $this->assertSame('failed', $export->fresh()->state);
        $this->assertSame(1, $posts);
    }

    private function source(string $mode = 'immediate', bool $confirmed = true): array
    {
        OrderStatusSetting::syncDefaults();
        $account = app(AllegroAccountService::class)->get();
        $account->forceFill(['client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(10), 'allegro_user_id' => 'seller-1', 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'is_active' => true, 'last_test_status' => AllegroAccount::STATUS_SUCCESS, 'correction_sync_mode' => $mode,
            'correction_sync_identity' => 'production:seller-1', 'correction_sync_revision' => 1,
            'correction_sync_status_setting_id' => OrderStatusSetting::query()->where('status', 'pending')->value('id')])->save();
        $order = $this->createDocumentOrder(['source' => 'allegro', 'external_id' => Fixture::ORDER_ID]);
        $link = AllegroOrderLink::query()->create(['allegro_account_id' => $account->id, 'order_id' => $order->id,
            'environment' => 'production', 'seller_user_id' => 'seller-1', 'account_name' => 'multi-click', 'external_order_id' => Fixture::ORDER_ID,
            'external_status' => 'READY_FOR_PROCESSING', 'fulfillment_status' => 'NEW', 'source_hash' => hash('sha256', Fixture::ORDER_ID),
            'imported_at' => now(), 'completed_at' => now(), 'import_state' => 'complete', 'invoice_required' => false]);
        $this->createDocumentItem($order);
        $root = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());
        if ($confirmed) {
            AllegroInvoiceExport::query()->create(['allegro_account_id' => $account->id, 'allegro_order_link_id' => $link->id,
                'order_id' => $order->id, 'invoice_id' => $root->id, 'environment' => $link->environment, 'seller_user_id' => $link->seller_user_id,
                'external_order_id' => $link->external_order_id, 'invoice_number' => $root->number, 'fingerprint' => hash('sha256', 'original'),
                'configuration_revision' => 0, 'attempt_token' => (string) Str::uuid(), 'state' => 'synced', 'confirmed_at' => now()]);
        }

        return [$root, $account->fresh(), $link];
    }

    private function saveSettings(AllegroAccount $account, string $mode): void
    {
        app(AllegroInvoiceSettingsService::class)->updateCorrections(['identity' => $account->orderImportIdentity(),
            'configuration_revision' => $account->correction_sync_revision, 'correction_sync_mode' => $mode]);
    }

    private function correctionExport(): AllegroInvoiceExport
    {
        return AllegroInvoiceExport::query()->where('document_type', 'correction')->sole();
    }

    private function fakeApi(array &$remote, bool $timeout = false, bool $cancelled = false, ?int &$posts = null): void
    {
        Http::fake(function (Request $request) use (&$remote, $timeout, $cancelled, &$posts) {
            $this->assertSame('NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)', $request->header('User-Agent')[0] ?? null);
            if ($request->method() === 'POST') {
                $posts++;
                if ($timeout) {
                    throw new ConnectionException('Response lost');
                }

                return Http::response(['id' => self::REMOTE_ID], 201);
            }
            $raw = Fixture::payload();
            if ($cancelled) {
                $raw['status'] = 'CANCELLED';
                $raw['fulfillment']['status'] = 'CANCELLED';
            }

            return Http::response(str_ends_with($request->url(), '/invoices') ? ['invoices' => $remote] : $raw);
        });
    }

    private function remote(AllegroInvoiceExport $export): array
    {
        return ['id' => self::REMOTE_ID, 'invoiceNumber' => $export->invoice_number,
            'file' => ['name' => $export->file_name, 'uploadedAt' => now()->toIso8601String(), 'securityVerification' => ['status' => 'ACCEPTED']]];
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
