<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\InvoiceBulkPdfService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFontResolver;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\InvoicePdfViewModelFactory;
use Modules\Invoices\Services\ProformaService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefOfflineStandardPdfGuard;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class InvoiceBulkPdfTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_selected_invoices_are_rendered_in_one_pdf_in_submitted_order(): void
    {
        $series = $this->createDocumentSeries();
        $firstOrder = $this->createDocumentOrder(['billing_name' => 'Pierwszy Nabywca']);
        $this->createDocumentItem($firstOrder);
        $first = app(InvoiceIssuingService::class)->issue(
            $firstOrder,
            $series,
            $this->documentContext('2026-08-01 10:00:00'),
        );

        $secondOrder = $this->createDocumentOrder(['billing_name' => 'Drugi Nabywca']);
        $this->createDocumentItem($secondOrder);
        $second = app(InvoiceIssuingService::class)->issue(
            $secondOrder,
            $series,
            $this->documentContext('2026-08-02 10:00:00'),
        );

        $response = $this->post(route('invoices.bulk-pdf'), [
            'selection' => $this->printSelection([$second->getKey(), $first->getKey()]),
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('filename="faktury-zbiorcze.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('Faktury zbiorcze', $this->pdfMetadataTitle($response->getContent()));
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
    }

    public function test_bulk_pdf_requires_at_least_one_invoice(): void
    {
        $this->from(route('invoices.index'))
            ->post(route('invoices.bulk-pdf'), [])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('selection');
    }

    public function test_bulk_pdf_rejects_empty_malformed_and_wrong_json_shapes(): void
    {
        foreach (['[]', '{invalid', '{"1":1}', '"1"'] as $selection) {
            $this->from(route('invoices.index'))
                ->post(route('invoices.bulk-pdf'), ['selection' => $selection])
                ->assertRedirect(route('invoices.index'))
                ->assertSessionHasErrors();
        }
    }

    public function test_bulk_pdf_rejects_invalid_and_duplicate_invoice_ids(): void
    {
        foreach ([['1'], [0], [-1], [1, 1]] as $invoiceIds) {
            $this->from(route('invoices.index'))
                ->post(route('invoices.bulk-pdf'), [
                    'selection' => $this->printSelection($invoiceIds),
                ])
                ->assertRedirect(route('invoices.index'))
                ->assertSessionHasErrors();
        }
    }

    public function test_bulk_pdf_rejects_missing_document(): void
    {
        $this->from(route('invoices.index'))
            ->post(route('invoices.bulk-pdf'), [
                'selection' => $this->printSelection([999999]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors([
                'invoice_ids' => 'Jedna z zaznaczonych faktur już nie istnieje.',
            ]);
    }

    public function test_each_bulk_pdf_endpoint_rejects_more_than_one_thousand_documents(): void
    {
        $selection = $this->printSelection(range(1, 1001));

        foreach ([
            [route('invoices.index'), route('invoices.bulk-pdf'), 'Jednorazowo można wydrukować maksymalnie 1000 faktur.'],
            [route('invoices.proformas.index'), route('invoices.proformas.bulk-pdf'), 'Jednorazowo można wydrukować maksymalnie 1000 Pro form.'],
            [route('invoices.corrections.index'), route('invoices.corrections.bulk-pdf'), 'Jednorazowo można wydrukować maksymalnie 1000 Korekt.'],
        ] as [$from, $route, $message]) {
            $this->from($from)
                ->post($route, ['selection' => $selection])
                ->assertRedirect($from)
                ->assertSessionHasErrors(['invoice_ids' => $message]);
        }
    }

    public function test_bulk_pdf_cannot_be_bypassed_with_legacy_invoice_fields(): void
    {
        $this->from(route('invoices.index'))
            ->post(route('invoices.bulk-pdf'), [
                'selection' => '{}',
                'invoice_ids' => [1],
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('invoice_ids');
    }

    public function test_bulk_pdf_rejects_proforma_selection(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;

        $this->from(route('invoices.index'))
            ->post(route('invoices.bulk-pdf'), [
                'selection' => $this->printSelection([$proforma->getKey()]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('invoice_ids');
    }

    public function test_selected_proformas_are_rendered_in_one_pdf_and_invoice_is_rejected(): void
    {
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $firstOrder = $this->createDocumentOrder(['billing_name' => 'Pierwsza Proforma']);
        $this->createDocumentItem($firstOrder);
        $first = app(ProformaService::class)->createOrRefresh(
            $firstOrder,
            $series,
            $this->documentContext('2026-08-01 10:00:00'),
        )->invoice;

        $secondOrder = $this->createDocumentOrder(['billing_name' => 'Druga Proforma']);
        $this->createDocumentItem($secondOrder);
        $second = app(ProformaService::class)->createOrRefresh(
            $secondOrder,
            $series,
            $this->documentContext('2026-08-02 10:00:00'),
        )->invoice;

        $response = $this->post(route('invoices.proformas.bulk-pdf'), [
            'selection' => $this->printSelection([$second->getKey(), $first->getKey()]),
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('filename="proformy-zbiorcze.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('Pro formy zbiorcze', $this->pdfMetadataTitle($response->getContent()));
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));

        $invoiceOrder = $this->createDocumentOrder();
        $this->createDocumentItem($invoiceOrder);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $invoiceOrder,
            $this->createDocumentSeries(),
            $this->documentContext('2026-08-03 10:00:00'),
        );

        $this->from(route('invoices.proformas.index'))
            ->post(route('invoices.proformas.bulk-pdf'), [
                'selection' => $this->printSelection([$invoice->getKey()]),
            ])
            ->assertRedirect(route('invoices.proformas.index'))
            ->assertSessionHasErrors('invoice_ids');
    }

    public function test_selected_corrections_are_rendered_in_one_pdf_and_invoice_is_rejected(): void
    {
        $series = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
        $first = $this->issueCorrectionForBulkPdf($series, 'Pierwsza Korekta', '2026-08-05 10:00:00');
        $second = $this->issueCorrectionForBulkPdf($series, 'Druga Korekta', '2026-08-05 11:00:00');

        $response = $this->post(route('invoices.corrections.bulk-pdf'), [
            'selection' => $this->printSelection([$second->getKey(), $first->getKey()]),
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('filename="korekty-zbiorcze.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('Korekty zbiorcze', $this->pdfMetadataTitle($response->getContent()));
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));

        $invoiceOrder = $this->createDocumentOrder();
        $this->createDocumentItem($invoiceOrder);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $invoiceOrder,
            $this->createDocumentSeries(),
            $this->documentContext('2026-08-06 10:00:00'),
        );

        $this->from(route('invoices.corrections.index'))
            ->post(route('invoices.corrections.bulk-pdf'), [
                'selection' => $this->printSelection([$invoice->getKey()]),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHasErrors([
                'invoice_ids' => 'Zbiorczy wydruk może zawierać wyłącznie wystawione Korekty.',
            ]);
    }

    public function test_empty_bulk_collection_uses_document_specific_message(): void
    {
        $service = app(InvoiceBulkPdfService::class);

        foreach ([
            InvoiceDocumentType::Invoice->value => [InvoiceDocumentType::Invoice, 'Zaznacz co najmniej jedną fakturę do wydruku.'],
            InvoiceDocumentType::Proforma->value => [InvoiceDocumentType::Proforma, 'Zaznacz co najmniej jedną Pro formę do wydruku.'],
            InvoiceDocumentType::Correction->value => [InvoiceDocumentType::Correction, 'Zaznacz co najmniej jedną Korektę do wydruku.'],
        ] as [$documentType, $message]) {
            try {
                $service->contents([], $documentType);
                $this->fail('Pusta kolekcja dokumentów powinna zostać odrzucona.');
            } catch (InvoiceDomainException $exception) {
                $this->assertSame('invoice_bulk_pdf_empty', $exception->errorCode());
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_single_pdf_keeps_document_number_as_metadata_title(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext('2026-08-07 10:00:00'),
        );

        $contents = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertSame($invoice->number, $this->pdfMetadataTitle($contents));
    }

    public function test_bulk_invoice_qr_payloads_keep_each_documents_own_date_and_hash(): void
    {
        Http::preventStrayRequests();
        $this->configureKsefEnvironment(KsefEnvironment::Demo);
        $series = $this->createDocumentSeries();
        $firstOrder = $this->createDocumentOrder();
        $this->createDocumentItem($firstOrder);
        $first = app(InvoiceIssuingService::class)->issue(
            $firstOrder,
            $series,
            $this->documentContext('2026-08-01 10:00:00'),
        );
        $secondOrder = $this->createDocumentOrder();
        $this->createDocumentItem($secondOrder);
        $second = app(InvoiceIssuingService::class)->issue(
            $secondOrder,
            $series,
            $this->documentContext('2026-08-02 10:00:00'),
        );
        $firstPayload = '<Faktura>BULK FIRST</Faktura>';
        $secondPayload = '<Faktura>BULK SECOND</Faktura>';
        $this->acceptedSubmission($first, KsefEnvironment::Demo, $firstPayload);
        $this->acceptedSubmission($second, KsefEnvironment::Demo, $secondPayload);
        $renderer = $this->recordingRenderer();

        $renderer->renderMany(collect([$second->fresh(), $first->fresh()]), InvoiceDocumentType::Invoice);

        $this->assertSame([
            $this->verificationUrl($second, KsefEnvironment::Demo, $secondPayload),
            $this->verificationUrl($first, KsefEnvironment::Demo, $firstPayload),
        ], $renderer->qrPayloads);
        Http::assertNothingSent();
    }

    public function test_bulk_correction_qr_payloads_are_isolated_and_proformas_never_emit_qr(): void
    {
        Http::preventStrayRequests();
        $this->configureKsefEnvironment(KsefEnvironment::Test);
        $series = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
        $first = $this->issueCorrectionForBulkPdf($series, 'Pierwsza Korekta KSeF', '2026-08-05 10:00:00');
        $second = $this->issueCorrectionForBulkPdf($series, 'Druga Korekta KSeF', '2026-08-06 10:00:00');
        $firstPayload = '<Faktura>BULK CORRECTION FIRST</Faktura>';
        $secondPayload = '<Faktura>BULK CORRECTION SECOND</Faktura>';
        $this->acceptedSubmission($first, KsefEnvironment::Test, $firstPayload);
        $this->acceptedSubmission($second, KsefEnvironment::Test, $secondPayload);
        $renderer = $this->recordingRenderer();

        $renderer->renderMany(collect([$first->fresh(), $second->fresh()]), InvoiceDocumentType::Correction);

        $this->assertSame([
            $this->verificationUrl($first, KsefEnvironment::Test, $firstPayload),
            $this->verificationUrl($second, KsefEnvironment::Test, $secondPayload),
        ], $renderer->qrPayloads);

        $proformaOrder = $this->createDocumentOrder();
        $this->createDocumentItem($proformaOrder);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $proformaOrder,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;
        $proformaRenderer = $this->recordingRenderer();
        $proformaRenderer->renderMany(collect([$proforma]), InvoiceDocumentType::Proforma);

        $this->assertSame([], $proformaRenderer->qrPayloads);
        Http::assertNothingSent();
    }

    /** @param array<int, mixed> $invoiceIds */
    private function printSelection(array $invoiceIds): string
    {
        return json_encode($invoiceIds, JSON_THROW_ON_ERROR);
    }

    private function pdfMetadataTitle(string $contents): string
    {
        $matched = preg_match('/\/Title\s*\((.*?)\)/s', $contents, $matches);

        $this->assertSame(1, $matched, 'Wygenerowany PDF nie zawiera metadanej Title.');

        $title = $matches[1];

        if (str_starts_with($title, "\xFE\xFF")) {
            return mb_convert_encoding(substr($title, 2), 'UTF-8', 'UTF-16BE');
        }

        return $title;
    }

    private function issueCorrectionForBulkPdf(
        InvoiceSeries $series,
        string $buyerName,
        string $occurredAt,
    ): Invoice {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $source = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext($occurredAt),
        );

        return app(CorrectionService::class)->issue(
            $source,
            $series,
            $source->getKey(),
            $source->lock_version,
            [
                'correction_series_id' => $series->getKey(),
                'reason' => CorrectionReason::InvoiceError->value,
                'other_reason' => null,
                'issue_date' => '2026-08-05',
                'sale_date' => '2026-07-20',
                'payment_method' => 'Przelew',
                'issuer_name' => 'Tester korekty',
                'additional_information' => null,
                'change_items' => false,
                'change_buyer' => true,
                'items' => [],
                'buyer' => array_merge($source->buyer_snapshot, ['name' => $buyerName]),
            ],
            $this->documentContext($occurredAt),
        );
    }

    private function configureKsefEnvironment(KsefEnvironment $environment): void
    {
        KsefSetting::query()->updateOrCreate(
            ['singleton_key' => KsefSetting::SINGLETON_KEY],
            ['environment' => $environment],
        );
    }

    private function acceptedSubmission(
        Invoice $document,
        KsefEnvironment $environment,
        string $payload,
    ): KsefInvoiceSubmission {
        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $document->getKey(),
            'environment' => $environment,
            'context_nip' => KsefUpoFixture::CONTEXT_NIP,
            'seller_nip' => KsefUpoFixture::SELLER_NIP,
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinute(),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'ksef_status_code' => 200,
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => '2026-08-24 09:51:15',
            'last_checked_at' => '2026-08-24 09:51:15',
        ]);
    }

    private function verificationUrl(
        Invoice $document,
        KsefEnvironment $environment,
        string $payload,
    ): string {
        $hash = rtrim(strtr(base64_encode(hash('sha256', $payload, true)), '+/', '-_'), '=');

        return config('ksef.qr_base_urls.'.$environment->value)
            .'/invoice/'.KsefUpoFixture::SELLER_NIP
            .'/'.$document->issue_date->format('d-m-Y')
            .'/'.$hash;
    }

    private function recordingRenderer(): InvoicePdfRenderer
    {
        return new class(app(InvoicePdfViewModelFactory::class), app(InvoicePdfFontResolver::class), app(KsefOfflineStandardPdfGuard::class)) extends InvoicePdfRenderer
        {
            /** @var array<int, string> */
            public array $qrPayloads = [];

            /** @param array<string, mixed> $style */
            protected function writeQrCode(
                \TCPDF $pdf,
                string $payload,
                float $x,
                float $y,
                float $size,
                array $style,
            ): void {
                $this->qrPayloads[] = $payload;
            }
        };
    }
}
