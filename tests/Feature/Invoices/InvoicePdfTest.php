<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_invoice_pdf_is_generated_privately_and_returned_inline(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $invoice = $this->issueInvoice();

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $storagePath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        Storage::disk('local')->assertExists($storagePath);
        Storage::disk('public')->assertMissing($storagePath);
        $this->assertSame([], array_values(array_filter(
            Storage::disk('local')->allFiles('invoices/'.$invoice->getKey()),
            fn (string $path): bool => str_ends_with($path, '.tmp'),
        )));
    }

    public function test_concurrent_pdf_winner_is_returned_without_leaving_a_partial_file(): void
    {
        Storage::fake('local');
        $invoice = $this->issueInvoice();
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        $winner = '%PDF-1.7 concurrent winner';

        $contents = app(InvoicePdfStorage::class)->getOrCreate($invoice, function () use ($path, $winner): string {
            Storage::disk('local')->put($path, $winner);

            return '%PDF-1.7 slower generator';
        });

        $this->assertSame($winner, $contents);
        $this->assertSame([$path], Storage::disk('local')->allFiles('invoices/'.$invoice->getKey()));
    }

    public function test_invoice_html_contains_snapshot_data_complete_table_and_no_generator_footer(): void
    {
        $invoice = $this->issueInvoice();
        $html = app(InvoicePdfRenderer::class)->html($invoice);

        foreach ([
            'Faktura VAT', $invoice->number, 'Data sprzedaży', 'Data wystawienia', 'Katowice',
            'Przelew', 'SHOP-100', 'Sprzedawca:', 'Nabywca:', 'Nazwa towaru/usługi',
            'Przesyłka:', 'Netto', 'VAT', 'Brutto', 'Razem:', 'Słownie:',
            'Operator NEX-OMS', 'SN-001',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertStringNotContainsString('Wygenerowano w', $html);
        $this->assertStringNotContainsString('Wygenerowano przez', $html);
        $this->assertStringNotContainsString('Odbiorca:', $html);
        $this->assertStringNotContainsString('REGON:', $html);
        $this->assertStringNotContainsString('>PL<br>', preg_replace('/\s+/', '', $html));
    }

    public function test_invoice_pdf_uses_snapshots_after_order_and_series_change(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(attributes: ['print_header' => 'Nagłówek historyczny']);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
        $renderer = app(InvoicePdfRenderer::class);
        $before = $renderer->html($invoice);

        $order->update(['billing_name' => 'Zmieniony klient', 'external_id' => 'NOWY-NUMER']);
        $series->update([
            'seller_name' => 'Zmieniony sprzedawca',
            'issuer_name' => 'Inna osoba',
            'print_header' => 'Zmieniony nagłówek',
        ]);
        $after = $renderer->html($invoice->refresh());

        $this->assertSame($before, $after);
        $this->assertStringContainsString('Nagłówek historyczny', $after);
        $this->assertStringNotContainsString('Zmieniony klient', $after);
        $this->assertStringNotContainsString('Zmieniony sprzedawca', $after);
        $this->assertStringNotContainsString('Zmieniony nagłówek', $after);
    }

    public function test_invoice_with_many_items_generates_a_multi_page_pdf(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();

        foreach (range(1, 70) as $position) {
            $this->createDocumentItem($order, [
                'product_name' => sprintf(
                    'Produkt testowy %02d z dłuższą nazwą kontrolującą układ tabeli',
                    $position,
                ),
            ]);
        }

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $contents = $this->get(route('invoices.pdf', $invoice))
            ->assertOk()
            ->getContent();

        preg_match_all('/\/Type\s*\/Page\b/', $contents, $matches);

        $this->assertGreaterThan(1, count($matches[0]));
    }

    public function test_invoice_pdf_uses_order_reference_when_external_id_snapshot_is_empty(): void
    {
        $invoice = $this->issueInvoice();
        $snapshot = $invoice->order_snapshot;
        $snapshot['external_id'] = null;
        $invoice->update([
            'order_reference_snapshot' => 'ORD-ALTERNATIVE-42',
            'order_snapshot' => $snapshot,
        ]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->refresh());

        $this->assertStringContainsString('ORD-ALTERNATIVE-42', $html);
    }

    public function test_download_filename_is_safe_and_preserves_document_kind(): void
    {
        $invoice = $this->issueInvoice();
        $invoice->number = 'FV 1/2026 \\ test:*?"<>|';

        $filename = app(InvoicePdfFilenameGenerator::class)->downloadName($invoice);

        $this->assertStringEndsWith('.pdf', $filename);
        $this->assertDoesNotMatchRegularExpression('/[\\\\\/:*?"<>|]/', $filename);

        $proforma = $invoice->replicate();
        $proforma->document_type = InvoiceDocumentType::Proforma;
        $proforma->number = 'PF 2/2026';
        $this->assertStringStartsWith(
            'Proforma_',
            app(InvoicePdfFilenameGenerator::class)->downloadName($proforma),
        );
    }

    public function test_proforma_pdf_uses_current_revision_path_and_hides_invoice_only_sections(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $invoice = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;
        $order->update(['notes' => 'Nowe uwagi']);
        $invoice = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;

        $html = app(InvoicePdfRenderer::class)->html($invoice);
        $this->assertStringContainsString('Faktura PRO FORMA', $html);
        $this->assertStringNotContainsString('Wersja', $html);
        $this->assertStringNotContainsString('Numer płatności:', $html);
        $this->assertStringNotContainsString('Osoba upoważniona', $html);
        $this->assertStringNotContainsString('Nowe uwagi', $html);

        $this->get(route('invoices.pdf', $invoice))->assertOk();
        Storage::disk('local')->assertExists(
            app(InvoicePdfFilenameGenerator::class)->storagePath($invoice),
        );
    }

    public function test_correction_pdf_uses_complete_before_after_and_difference_snapshots(): void
    {
        Storage::fake('local');
        $source = $this->issueInvoice();
        $correction = $this->createCorrection($source);
        $html = app(InvoicePdfRenderer::class)->html($correction);

        foreach ([
            'Faktura korygująca', 'KOR 1/2026', 'do faktury '.$source->number,
            'Błąd w cenie', 'Było:', 'Powinno być:', 'Podsumowanie:',
            'Kwota zmniejszająca podstawę opodatkowania', 'Do zwrotu', '-20.00',
            '- Dwadzieścia PLN 00/100 PLN', 'Operator korekty',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $response = $this->get(route('invoices.pdf', $correction))->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_increasing_correction_uses_increasing_labels_and_amount_due(): void
    {
        $source = $this->issueInvoice();
        $correction = $this->createCorrection($source);
        $correction->update([
            'correction_totals_snapshot' => [
                'source_invoice' => [
                    'number' => $source->number,
                    'issue_date' => $source->issue_date->toDateString(),
                ],
                'before' => ['net' => '100.00', 'vat' => '23.00', 'gross' => '123.00'],
                'after' => ['net' => '116.26', 'vat' => '26.74', 'gross' => '143.00'],
                'difference' => ['net' => '16.26', 'vat' => '3.74', 'gross' => '20.00'],
            ],
            'total_net' => '16.26',
            'total_vat' => '3.74',
            'total_gross' => '20.00',
        ]);

        $html = app(InvoicePdfRenderer::class)->html($correction->refresh());

        $this->assertStringContainsString('Kwota zwiększająca podstawę opodatkowania', $html);
        $this->assertStringContainsString('Kwota zwiększająca podatek VAT', $html);
        $this->assertStringContainsString('Do zapłaty', $html);
    }

    public function test_incomplete_correction_snapshots_return_controlled_error(): void
    {
        $source = $this->issueInvoice();
        $correction = $this->createCorrection($source);
        $correction->items()->firstOrFail()->update(['correction_after_snapshot' => null]);

        $this->get(route('invoices.pdf', $correction))
            ->assertUnprocessable()
            ->assertSeeText('dane dokumentu są niekompletne');
    }

    public function test_draft_and_incomplete_document_return_controlled_errors(): void
    {
        $series = $this->createDocumentSeries();
        $draft = Invoice::query()->create([
            'invoice_series_id' => $series->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceDocumentStatus::Draft,
        ]);

        $this->get(route('invoices.pdf', $draft))
            ->assertNotFound()
            ->assertSeeText('wyłącznie dla wystawionego dokumentu');

        $draft->update(['status' => InvoiceDocumentStatus::Issued, 'number' => 'FV 99/2026']);
        $this->get(route('invoices.pdf', $draft))
            ->assertUnprocessable()
            ->assertSeeText('dane dokumentu są niekompletne');
    }

    private function issueInvoice(): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        return app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
    }

    private function createCorrection(Invoice $source): Invoice
    {
        $series = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        $correction = Invoice::query()->create([
            'order_id' => $source->order_id,
            'invoice_series_id' => $series->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'KOR 1/2026',
            'sequence_number' => 1,
            'numbering_period_key' => '2026',
            'number_format_snapshot' => 'KOR %N/%Y',
            'series_name_snapshot' => $series->name,
            'issue_date' => '2026-07-29',
            'sale_date' => '2026-07-20',
            'issued_at' => '2026-07-29 12:00:00',
            'corrected_invoice_id' => $source->getKey(),
            'correction_reason' => 'Błąd w cenie',
            'correction_totals_snapshot' => [
                'source_invoice' => ['number' => $source->number, 'issue_date' => $source->issue_date->toDateString()],
                'before' => ['net' => '100.00', 'vat' => '23.00', 'gross' => '123.00'],
                'after' => ['net' => '83.74', 'vat' => '19.26', 'gross' => '103.00'],
                'difference' => ['net' => '-16.26', 'vat' => '-3.74', 'gross' => '-20.00'],
            ],
            'order_reference_snapshot' => $source->order_reference_snapshot,
            'seller_name_snapshot' => $source->seller_name_snapshot,
            'buyer_name_snapshot' => $source->buyer_name_snapshot,
            'seller_snapshot' => $source->seller_snapshot,
            'buyer_snapshot' => $source->buyer_snapshot,
            'recipient_snapshot' => $source->recipient_snapshot,
            'issuer_snapshot' => ['place_of_issue' => 'Katowice', 'issuer_name' => 'Operator korekty'],
            'order_snapshot' => ['corrected_invoice' => [
                'number' => $source->number,
                'issue_date' => $source->issue_date->toDateString(),
            ]],
            'payment_snapshot' => $source->payment_snapshot,
            'shipping_snapshot' => $source->shipping_snapshot,
            'series_settings_snapshot' => $source->series_settings_snapshot,
            'tax_summary_snapshot' => [],
            'currency' => 'PLN',
            'total_net' => '-16.26',
            'total_vat' => '-3.74',
            'total_gross' => '-20.00',
        ]);

        $before = [
            'line_type' => 'product', 'name' => 'Produkt przed korektą', 'unit_name' => 'szt.',
            'quantity' => '1.0000', 'unit_price_net' => '100.0000', 'total_net' => '100.00',
            'total_vat' => '23.00', 'total_gross' => '123.00', 'vat_rate' => '23.00', 'vat_code' => null,
        ];
        $after = [
            'line_type' => 'product', 'name' => 'Produkt po korekcie', 'unit_name' => 'szt.',
            'quantity' => '1.0000', 'unit_price_net' => '83.7400', 'total_net' => '83.74',
            'total_vat' => '19.26', 'total_gross' => '103.00', 'vat_rate' => '23.00', 'vat_code' => null,
        ];

        $correction->items()->create([
            'line_type' => InvoiceItemType::Product,
            'position' => 1,
            'name' => 'Korekta produktu',
            'correction_before_snapshot' => $before,
            'correction_after_snapshot' => $after,
            'correction_difference_snapshot' => [
                'line_type' => 'product', 'name' => 'Różnica', 'unit_name' => 'szt.',
                'quantity' => '0.0000', 'unit_price_net' => '-16.2600', 'total_net' => '-16.26',
                'total_vat' => '-3.74', 'total_gross' => '-20.00', 'vat_rate' => '23.00', 'vat_code' => null,
            ],
        ]);

        return $correction->refresh();
    }
}
