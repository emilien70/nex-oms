<?php

namespace Tests\Feature\Invoices;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Enums\InvoicePaymentDueMode;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Invoices\Services\InvoicePdfViewModelFactory;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_invoice_pdf_is_generated_privately_and_returned_inline(): void
    {
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

    public function test_foreign_invoice_view_model_and_html_use_only_stored_conversion_snapshot(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->foreignInvoice();
        $before = $invoice->getAttributes();
        Currency::query()->whereKey('EUR')->delete();

        $document = app(InvoicePdfViewModelFactory::class)->make($invoice->fresh());
        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh());

        $this->assertSame('4.3420', $document['pln_conversion']['rate']);
        $this->assertSame('1 EUR = 4.3420 PLN', $document['pln_conversion']['rate_text']);
        $this->assertSame('434.20', $document['pln_conversion']['totals']['net']);
        foreach (['Razem (EUR):', 'Razem (PLN):', 'W tym (EUR):', 'W tym (PLN):', '1 EUR = 4.3420 PLN', '2026-07-17', '137/A/NBP/2026'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(2, substr_count($html, 'class="summary conversion-summary"'));
        $this->assertStringContainsString('.conversion-summary td { font-size: 7.5pt; }', $html);
        $this->assertStringContainsString('.exchange-rate td { font-size: 8.5pt; }', $html);
        $this->assertStringContainsString('.grand-total-label { vertical-align: bottom !important; }', $html);
        $this->assertStringContainsString('class="muted-label grand-total-label">Razem:</td>', $html);
        $this->assertStringContainsString('123.00 EUR', $html);
        $this->assertStringContainsString('EUR 00/100 EUR', $html);
        $this->assertSame(1, substr_count($html, '1 EUR = 4.3420 PLN'));
        $this->assertSame(1, substr_count($html, '137/A/NBP/2026'));
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_pln_invoice_proforma_and_legacy_foreign_invoice_keep_layout_without_pln_conversion(): void
    {
        Http::preventStrayRequests();
        $pln = $this->issueInvoice();
        $legacy = $this->issueInvoice();
        $legacy->update(['currency' => 'EUR', 'tax_metadata_snapshot' => []]);
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;

        foreach ([$pln, $legacy->fresh(), $proforma] as $document) {
            $viewModel = app(InvoicePdfViewModelFactory::class)->make($document);
            $html = app(InvoicePdfRenderer::class)->html($document);

            $this->assertNull($viewModel['pln_conversion']);
            $this->assertSame([], $viewModel['tax_row_pairs']);
            $this->assertStringNotContainsString('Kurs waluty:', $html);
            $this->assertStringNotContainsString('W tym (PLN):', $html);
        }

        Http::assertNothingSent();
    }

    public function test_documents_use_one_current_layout_versioned_cache_file(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $invoice = $this->foreignInvoice();
        $oldPath = 'invoices/'.$invoice->getKey().'/invoice-v39.pdf';
        Storage::disk('local')->put($oldPath, '%PDF-1.7 old layout');

        $first = $this->get(route('invoices.pdf', $invoice))->assertOk()->getContent();
        $newPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        $second = $this->get(route('invoices.pdf', $invoice))->assertOk()->getContent();

        $this->assertStringEndsWith('/invoice-v41.pdf', $newPath);
        $this->assertSame($first, $second);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
        $this->assertSame([$newPath], Storage::disk('local')->allFiles('invoices/'.$invoice->getKey()));

        $proforma = $invoice->replicate();
        $proforma->id = 9001;
        $proforma->document_type = InvoiceDocumentType::Proforma;
        $proforma->lock_version = 3;
        $correction = $invoice->replicate();
        $correction->id = 9002;
        $correction->document_type = InvoiceDocumentType::Correction;
        $filenames = app(InvoicePdfFilenameGenerator::class);
        $this->assertStringEndsWith('/proforma-v34.pdf', $filenames->storagePath($proforma));
        $this->assertStringEndsWith('/correction-v33.pdf', $filenames->storagePath($correction));
        Http::assertNothingSent();
    }

    public function test_invoice_and_proforma_show_payment_due_date_below_issue_date(): void
    {
        $seriesSettings = [
            'payment_due_mode' => InvoicePaymentDueMode::DaysFromIssue,
            'payment_due_days' => 9,
        ];
        $invoice = $this->issueInvoice($seriesSettings);
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma, $seriesSettings),
            $this->documentContext(),
        )->invoice;

        foreach ([$invoice, $proforma] as $document) {
            $viewModel = app(InvoicePdfViewModelFactory::class)->make($document);
            $html = app(InvoicePdfRenderer::class)->html($document);

            $this->assertSame('06.08.2026', $viewModel['payment_due_date']);
            $this->assertStringContainsString('Termin płatności:', $html);
            $this->assertStringContainsString('06.08.2026', $html);
            $this->assertMatchesRegularExpression('/Data wystawienia:.*?Termin płatności:/s', $html);
        }
    }

    public function test_invoice_and_proforma_omit_payment_due_row_when_date_is_empty(): void
    {
        $invoice = $this->issueInvoice();
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;

        foreach ([$invoice, $proforma] as $document) {
            $viewModel = app(InvoicePdfViewModelFactory::class)->make($document);
            $html = app(InvoicePdfRenderer::class)->html($document);

            $this->assertNull($viewModel['payment_due_date']);
            $this->assertStringNotContainsString('Termin płatności:', $html);
        }
    }

    public function test_invalid_nonempty_conversion_snapshot_returns_controlled_error_and_cleans_temporary_file(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $invoice = $this->foreignInvoice();
        $metadata = $invoice->tax_metadata_snapshot;
        unset($metadata['converted_tax_summary']);
        $invoice->update(['tax_metadata_snapshot' => $metadata]);

        $this->get(route('invoices.pdf', $invoice->fresh()))
            ->assertUnprocessable()
            ->assertSeeText('zapisane dane przeliczenia walutowego są niekompletne');

        $this->assertSame([], Storage::disk('local')->allFiles('invoices/'.$invoice->getKey()));
        Http::assertNothingSent();
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
        $invoice = $this->issueInvoice([
            'seller_bank_name' => 'Bank testowy',
            'seller_bank_account' => '12 3456 7890 1234 5678 9012 3456',
        ]);
        $html = app(InvoicePdfRenderer::class)->html($invoice);

        foreach ([
            'Faktura VAT', $invoice->number, 'Data sprzedaży', 'Data wystawienia', 'Katowice',
            'Przelew', 'SHOP-100',
            'Sprzedawca:', 'Nabywca:', 'Nazwa towaru/usługi',
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
        $this->assertStringNotContainsString('Faktura do faktury pro forma:', $html);
        $this->assertStringNotContainsString('Bank testowy', $html);
        $this->assertStringNotContainsString('12 3456 7890 1234 5678 9012 3456', $html);
    }

    public function test_buyer_country_is_formatted_from_snapshot_for_invoice_proforma_and_correction(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $correction = $this->createCorrection($invoice);

        foreach ([$invoice, $proforma, $correction] as $document) {
            $html = app(InvoicePdfRenderer::class)->html($document);

            $this->assertStringContainsString('00-001 Warszawa, Polska', $html);
            $this->assertStringNotContainsString('00-001, Warszawa, Polska', $html);
            $this->assertStringNotContainsString('>PL<br>', preg_replace('/\s+/', '', $html));
        }
    }

    public function test_buyer_country_uses_polish_name_for_foreign_country(): void
    {
        $invoice = $this->issueInvoiceForOrder(['billing_country_code' => 'DE']);
        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertStringContainsString('00-001 Warszawa, Niemcy', $html);
        $this->assertStringNotContainsString('>DE<br>', preg_replace('/\s+/', '', $html));
    }

    public function test_legacy_buyer_snapshot_resolves_country_name_from_country_code(): void
    {
        $invoice = $this->issueInvoiceForOrder(['billing_country_code' => 'DE']);
        $snapshot = $invoice->buyer_snapshot;
        unset($snapshot['country_name']);
        $invoice->update(['buyer_snapshot' => $snapshot]);

        $html = app(InvoicePdfRenderer::class)->html($invoice->refresh());

        $this->assertStringContainsString('00-001 Warszawa, Niemcy', $html);
    }

    public function test_missing_buyer_country_does_not_add_poland_to_pdf(): void
    {
        $invoice = $this->issueInvoiceForOrder(['billing_country_code' => null]);
        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertStringContainsString('00-001 Warszawa', $html);
        $this->assertStringNotContainsString('Polska', $html);
    }

    public function test_buyer_locality_handles_partial_address_snapshots(): void
    {
        $invoice = $this->issueInvoiceForOrder();
        $base = $invoice->buyer_snapshot;
        $scenarios = [
            [['postal_code' => null, 'city' => 'Psary'], 'Psary, Polska'],
            [['postal_code' => '32-545', 'city' => null], '32-545, Polska'],
            [['postal_code' => '32-545', 'city' => 'Psary', 'country_code' => null, 'country_name' => null], '32-545 Psary'],
            [['postal_code' => null, 'city' => 'Psary', 'country_code' => null, 'country_name' => null], 'Psary'],
            [['postal_code' => null, 'city' => null], 'Polska'],
        ];

        foreach ($scenarios as [$changes, $expected]) {
            $invoice->update(['buyer_snapshot' => array_merge($base, $changes)]);

            $this->assertStringContainsString(
                $expected,
                app(InvoicePdfRenderer::class)->html($invoice->refresh()),
            );
        }
    }

    public function test_invoice_pdf_uses_effective_payment_method_selected_by_series(): void
    {
        $order = $this->createDocumentOrder(['payment_method' => 'Metoda z zamówienia']);
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(attributes: [
            'payment_method_source' => 'fixed',
            'fixed_payment_method' => 'Metoda stała serii',
            'seller_bank_name' => 'Bank ukryty',
            'seller_bank_account' => '00 1111 2222 3333 4444 5555 6666',
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());

        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertStringContainsString('Metoda stała serii', $html);
        $this->assertStringNotContainsString('Metoda z zamówienia', $html);
        $this->assertStringNotContainsString('Bank ukryty', $html);
        $this->assertStringNotContainsString('00 1111 2222 3333 4444 5555 6666', $html);
    }

    public function test_invoice_pdf_uses_snapshots_after_order_and_series_change(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(attributes: [
            'document_title' => 'Faktura historyczna',
            'print_header' => 'Nagłówek historyczny',
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
        $renderer = app(InvoicePdfRenderer::class);
        $before = $renderer->html($invoice);

        $order->update([
            'billing_name' => 'Zmieniony klient',
            'billing_country_code' => 'DE',
            'external_id' => 'NOWY-NUMER',
        ]);
        $series->update([
            'document_title' => 'Zmieniona nazwa dokumentu',
            'seller_name' => 'Zmieniony sprzedawca',
            'issuer_name' => 'Inna osoba',
            'print_header' => 'Zmieniony nagłówek',
        ]);
        $after = $renderer->html($invoice->refresh());

        $this->assertSame($before, $after);
        $this->assertSame('PL', $invoice->refresh()->buyer_snapshot['country_code']);
        $this->assertSame('Polska', $invoice->buyer_snapshot['country_name']);
        $this->assertStringContainsString('Faktura historyczna', $after);
        $this->assertStringNotContainsString('Zmieniona nazwa dokumentu', $after);
        $this->assertStringContainsString('Nagłówek historyczny', $after);
        $this->assertStringNotContainsString('Zmieniony klient', $after);
        $this->assertStringNotContainsString('Zmieniony sprzedawca', $after);
        $this->assertStringNotContainsString('Zmieniony nagłówek', $after);
    }

    public function test_invoice_pdf_shows_related_proforma_number_from_its_snapshot(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertMatchesRegularExpression(
            '/Faktura do faktury pro forma:\s*<br>\s*'.preg_quote($proforma->number, '/').'/',
            $html,
        );
        $this->assertStringContainsString($proforma->number, $html);

        $snapshotNumber = $invoice->order_snapshot['related_documents']['proforma']['number'];
        $proforma->update(['number' => 'PF ZMIENIONA']);

        $html = app(InvoicePdfRenderer::class)->html($invoice->refresh());
        $this->assertStringContainsString($snapshotNumber, $html);
        $this->assertStringNotContainsString('PF ZMIENIONA', $html);
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

    public function test_proforma_pdf_uses_current_cache_path_and_hides_invoice_only_sections(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma, [
            'document_title' => 'Pro forma testowa',
            'seller_bank_name' => 'Bank pro formy',
            'seller_bank_account' => '98 7654 3210 9876 5432 1098 7654',
        ]);
        $service = app(ProformaService::class);
        $invoice = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;
        $order->update(['notes' => 'Nowe uwagi']);
        $invoice = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;

        $html = app(InvoicePdfRenderer::class)->html($invoice);
        $this->assertStringContainsString('Pro forma testowa', $html);
        $this->assertStringNotContainsString('Faktura PRO FORMA', $html);
        $this->assertStringNotContainsString('Wersja', $html);
        $this->assertStringNotContainsString('Numer płatności:', $html);
        $this->assertStringNotContainsString('Osoba upoważniona', $html);
        $this->assertStringNotContainsString('Nowe uwagi', $html);
        $this->assertStringContainsString('Przelew', $html);
        $this->assertStringNotContainsString('Bank pro formy', $html);
        $this->assertStringNotContainsString('98 7654 3210 9876 5432 1098 7654', $html);
        $this->assertStringContainsString('<td width="15%" class="muted-label">Razem:</td>', $html);
        $this->assertStringContainsString('<td width="15%" class="muted-label">Słownie:</td>', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<td width="2%"></td>'));

        $this->get(route('invoices.pdf', $invoice))->assertOk();
        Storage::disk('local')->assertExists(
            app(InvoicePdfFilenameGenerator::class)->storagePath($invoice),
        );
    }

    public function test_correction_pdf_uses_complete_before_after_and_difference_snapshots(): void
    {
        Storage::fake('local');
        $source = $this->issueInvoice([
            'seller_bank_name' => 'Bank korekty',
            'seller_bank_account' => '11 2222 3333 4444 5555 6666 7777',
        ]);
        $correction = $this->createCorrection($source);
        $html = app(InvoicePdfRenderer::class)->html($correction);

        foreach ([
            'Korekta testowa', 'KOR 1/2026', 'do faktury '.$source->number,
            'Błąd w cenie', 'Było:', 'Powinno być:', 'Podsumowanie:',
            'Kwota zmniejszająca podstawę opodatkowania', 'Do zwrotu', '-20.00',
            '- Dwadzieścia PLN 00/100 PLN', 'Operator korekty',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertStringNotContainsString('Faktura korygująca', $html);
        $this->assertStringContainsString('Przelew', $html);
        $this->assertStringNotContainsString('Bank korekty', $html);
        $this->assertStringNotContainsString('11 2222 3333 4444 5555 6666 7777', $html);

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

    /**
     * @param  array<string, mixed>  $seriesAttributes
     */
    private function issueInvoice(array $seriesAttributes = []): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(attributes: $seriesAttributes);

        return app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
    }

    private function issueInvoiceForOrder(array $orderAttributes = []): Invoice
    {
        $order = $this->createDocumentOrder($orderAttributes);
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
    }

    private function foreignInvoice(): Invoice
    {
        $invoice = $this->issueInvoice();
        $invoice->update([
            'currency' => 'EUR',
            'tax_metadata_snapshot' => [
                'currency_conversion' => [
                    'version' => 1,
                    'source' => 'NBP',
                    'source_currency' => 'EUR',
                    'target_currency' => 'PLN',
                    'table_type' => 'A',
                    'table_number' => '137/A/NBP/2026',
                    'effective_date' => '2026-07-17',
                    'reference_date' => '2026-07-20',
                    'rate' => '4.3420',
                    'rate_rule' => 'vat_art_31a_standard_v1',
                    'rounding_mode' => 'half_up',
                    'result_scale' => 2,
                ],
                'converted_tax_summary' => [
                    'currency' => 'PLN',
                    'groups' => [[
                        'vat_rate' => '23.00',
                        'vat_code' => null,
                        'net' => '434.20',
                        'vat' => '99.87',
                        'gross' => '534.07',
                    ]],
                    'total_net' => '434.20',
                    'total_vat' => '99.87',
                    'total_gross' => '534.07',
                ],
            ],
        ]);

        return $invoice->fresh('items');
    }

    private function createCorrection(Invoice $source): Invoice
    {
        $series = $this->createDocumentSeries(InvoiceDocumentType::Correction, [
            'document_title' => 'Korekta testowa',
        ]);
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
            'series_settings_snapshot' => array_merge(
                $source->series_settings_snapshot,
                ['document_title' => $series->document_title],
            ),
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
