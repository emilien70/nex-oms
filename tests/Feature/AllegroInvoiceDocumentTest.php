<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroInvoiceDocumentService;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class AllegroInvoiceDocumentTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_real_correction_pdf_requires_finalization_and_does_not_change_snapshots(): void
    {
        [$root, $account] = $this->document();
        $correction = $this->issueKsefFinancialCorrection($root);
        try {
            app(AllegroInvoiceDocumentService::class)->contents($correction, $account);
            $this->fail('Editable correction accepted.');
        } catch (AllegroApiException $exception) {
            $this->assertSame(422, $exception->statusCode);
        }
        $correction = $this->finalizeKsefCorrection($correction);
        $before = $correction->getAttributes();
        $items = $correction->items->map->getAttributes()->all();
        $pdf = app(AllegroInvoiceDocumentService::class)->contents($correction, $account);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertLessThanOrEqual(AllegroInvoiceDocumentService::MAX_FILE_BYTES, strlen($pdf));
        $this->assertSame($before, $correction->fresh()->getAttributes());
        $this->assertSame($items, $correction->fresh()->items->map->getAttributes()->all());
        Http::assertNothingSent();
    }

    public function test_uses_real_existing_renderer_and_not_a_cached_preview(): void
    {
        [$invoice, $account] = $this->document();
        $before = $invoice->getAttributes();
        Storage::disk('local')->put(app(InvoicePdfFilenameGenerator::class)->storagePath($invoice), '%PDF-OUTDATED-PREVIEW');
        $contents = app(AllegroInvoiceDocumentService::class)->contents($invoice, $account);
        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertNotSame('%PDF-OUTDATED-PREVIEW', $contents);
        $this->assertLessThanOrEqual(AllegroInvoiceDocumentService::MAX_FILE_BYTES, strlen($contents));
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_non_pdf_or_oversized_file_is_rejected_before_upload(): void
    {
        [$invoice, $account] = $this->document();
        $this->mock(InvoicePdfRenderer::class)->shouldReceive('render')->andReturn('not-pdf');
        try {
            app(AllegroInvoiceDocumentService::class)->contents($invoice, $account);
            $this->fail('Invalid PDF accepted.');
        } catch (AllegroApiException $exception) {
            $this->assertSame(422, $exception->statusCode);
        }
        $this->mock(InvoicePdfRenderer::class)->shouldReceive('render')->andReturn('%PDF-'.str_repeat('x', AllegroInvoiceDocumentService::MAX_FILE_BYTES));
        $this->expectException(AllegroApiException::class);
        app(AllegroInvoiceDocumentService::class)->contents($invoice, $account);
    }

    public function test_proforma_and_draft_are_not_accepted_as_sales_invoices(): void
    {
        [$invoice, $account] = $this->document();
        foreach ([['document_type' => 'proforma'], ['document_type' => 'invoice', 'status' => 'draft']] as $change) {
            $invoice->forceFill($change);
            try {
                app(AllegroInvoiceDocumentService::class)->contents($invoice, $account);
                $this->fail('Non-issued VAT document accepted.');
            } catch (AllegroApiException $exception) {
                $this->assertSame(422, $exception->statusCode);
            }
        }
    }

    public function test_ksef_preview_is_blocked_until_production_acceptance(): void
    {
        [$invoice, $account] = $this->document();
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Production]);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $invoice->invoice_series_id, 'is_enabled' => true]);
        $service = app(AllegroInvoiceDocumentService::class);
        $this->assertNotNull($service->waitingReason($invoice, $account));
        $this->submission($invoice, KsefEnvironment::Production, KsefInvoiceSubmissionStatus::Rejected);
        $this->assertNotNull($service->waitingReason($invoice, $account));
        $this->expectException(AllegroApiException::class);
        $service->contents($invoice, $account);
    }

    public function test_accepted_test_ksef_invoice_is_still_blocked_for_production_allegro(): void
    {
        [$invoice, $account] = $this->document();
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Test]);
        $this->submission($invoice, KsefEnvironment::Test);
        $this->expectException(AllegroApiException::class);
        app(AllegroInvoiceDocumentService::class)->contents($invoice, $account);
    }

    public function test_final_online_ksef_pdf_uses_existing_verified_presentation(): void
    {
        [$invoice, $account] = $this->document();
        KsefSetting::query()->updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => KsefEnvironment::Production]);
        $this->submission($invoice, KsefEnvironment::Production);
        $service = app(AllegroInvoiceDocumentService::class);
        $this->assertNull($service->waitingReason($invoice, $account));
        $this->assertStringStartsWith('%PDF-', $service->contents($invoice, $account));
        Http::assertNothingSent();
    }

    public function test_semantic_hash_detects_item_and_snapshot_changes_but_ignores_timestamps(): void
    {
        [$invoice] = $this->document();
        $service = app(AllegroInvoiceDocumentService::class);
        $before = $service->hash($invoice);
        $invoice->touch();
        $this->assertSame($before, $service->hash($invoice->fresh()));
        $invoice->items()->firstOrFail()->update(['name' => 'Zmieniona pozycja']);
        $this->assertNotSame($before, $service->hash($invoice->fresh()));
    }

    private function document(): array
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());

        return [$invoice, new AllegroAccount(['environment' => 'production'])];
    }

    private function submission(Invoice $invoice, KsefEnvironment $environment, KsefInvoiceSubmissionStatus $status = KsefInvoiceSubmissionStatus::Accepted): KsefInvoiceSubmission
    {
        $xml = '<Faktura>TEST</Faktura>';

        return KsefInvoiceSubmission::query()->create(['invoice_id' => $invoice->id, 'environment' => $environment,
            'context_nip' => KsefUpoFixture::SELLER_NIP, 'seller_nip' => KsefUpoFixture::SELLER_NIP,
            'attempt_number' => 1, 'status' => $status, 'invoicing_mode' => KsefInvoicingMode::Online,
            'schema_id' => 'FA (3) 1-0E', 'generated_at' => now(), 'payload_xml' => $xml,
            'invoice_hash' => base64_encode(hash('sha256', $xml, true)), 'invoice_size' => strlen($xml),
            'ksef_number' => $status === KsefInvoiceSubmissionStatus::Accepted ? KsefUpoFixture::ksefNumber() : null,
            'acquisition_date' => $status === KsefInvoiceSubmissionStatus::Accepted ? now() : null]);
    }
}
