<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\InvoiceIssuingService;
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
            'invoice_ids' => [$second->getKey(), $first->getKey()],
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
    }

    public function test_bulk_pdf_requires_at_least_one_invoice(): void
    {
        $this->from(route('invoices.index'))
            ->post(route('invoices.bulk-pdf'), [])
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
            ->post(route('invoices.bulk-pdf'), ['invoice_ids' => [$proforma->getKey()]])
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
            'invoice_ids' => [$second->getKey(), $first->getKey()],
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('filename="proformy-zbiorcze.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));

        $invoiceOrder = $this->createDocumentOrder();
        $this->createDocumentItem($invoiceOrder);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $invoiceOrder,
            $this->createDocumentSeries(),
            $this->documentContext('2026-08-03 10:00:00'),
        );

        $this->from(route('invoices.proformas.index'))
            ->post(route('invoices.proformas.bulk-pdf'), ['invoice_ids' => [$invoice->getKey()]])
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
            'invoice_ids' => [$second->getKey(), $first->getKey()],
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('filename="korekty-zbiorcze.pdf"', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThanOrEqual(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));

        $invoiceOrder = $this->createDocumentOrder();
        $this->createDocumentItem($invoiceOrder);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $invoiceOrder,
            $this->createDocumentSeries(),
            $this->documentContext('2026-08-06 10:00:00'),
        );

        $this->from(route('invoices.corrections.index'))
            ->post(route('invoices.corrections.bulk-pdf'), ['invoice_ids' => [$invoice->getKey()]])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHasErrors([
                'invoice_ids' => 'Zbiorczy wydruk może zawierać wyłącznie wystawione Korekty.',
            ]);
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
