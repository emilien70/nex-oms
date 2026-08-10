<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\InvoiceBulkPdfService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
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
}
