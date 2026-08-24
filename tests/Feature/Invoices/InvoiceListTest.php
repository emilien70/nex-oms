<?php

namespace Tests\Feature\Invoices;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\ProformaService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceListTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_list_shows_only_issued_vat_invoices_with_supported_actions(): void
    {
        $invoiceOrder = $this->createDocumentOrder(['billing_name' => 'Jan Faktura']);
        $this->createDocumentItem($invoiceOrder);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $invoiceOrder,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $proformaOrder = $this->createDocumentOrder(['billing_name' => 'Anna Proforma']);
        $this->createDocumentItem($proformaOrder);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $proformaOrder,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext('2026-07-28 13:00:00'),
        )->invoice;

        $response = $this->get(route('invoices.index'));

        $response->assertOk()
            ->assertSee($invoice->number)
            ->assertDontSee($proforma->number)
            ->assertSee(route('invoices.pdf', $invoice), false)
            ->assertSee(route('invoices.edit', [
                'invoice' => $invoice,
                'return_to' => 'invoices',
            ]), false)
            ->assertSee(route('invoices.destroy', $invoice), false)
            ->assertSee(route('invoices.bulk-pdf'), false)
            ->assertSee(route('invoices.bulk-delete'), false)
            ->assertSee('ZAZNACZ WSZYSTKO')
            ->assertSee('DRUKUJ ZAZNACZONE')
            ->assertSee('REJESTR SPRZEDAŻY')
            ->assertSee('USUŃ ZAZNACZONE')
            ->assertSee('SORTOWANIE');

        $html = $response->getContent();
        $this->assertUsesJsonBulkSelection(
            $html,
            $invoice,
            route('invoices.bulk-pdf'),
            route('invoices.bulk-delete'),
        );
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*class="invoice-row-number"[^>]*href="'.preg_quote(route('invoices.pdf', $invoice), '/').'"[^>]*>/',
            $html,
        );
        $this->assertMatchesRegularExpression('/<button\b[^>]*data-bulk-print[^>]*>.*?<\/button>/s', $html);
        preg_match('/<button\b[^>]*data-bulk-print[^>]*>(.*?)<\/button>/s', $html, $printButton);
        $this->assertStringNotContainsString('dropdown-toggle', $printButton[0]);
        $this->assertStringNotContainsString('bi-chevron-down', $printButton[1]);
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*disabled[^>]*title="Rejestr sprzedaży nie jest jeszcze dostępny"[^>]*>.*?REJESTR SPRZEDAŻY.*?<\/button>/s',
            $html,
        );
    }

    public function test_invoice_list_actions_preserve_the_sanitized_list_query(): void
    {
        $order = $this->createDocumentOrder(['billing_name' => 'Jan Faktura']);
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $filters = [
            'buyer' => 'Jan',
            'year' => 2026,
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => 100,
            'page' => 1,
        ];
        $returnFilters = [
            'page' => 1,
            'year' => 2026,
            'buyer' => 'Jan',
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => 100,
        ];
        $returnQuery = http_build_query($returnFilters, '', '&', PHP_QUERY_RFC3986);

        $response = $this->get(route('invoices.index', [
            ...$filters,
            'unexpected' => 'remove-me',
        ]));
        $correctionSeries = $response->viewData('correctionSeries')->firstOrFail();

        $response->assertOk()
            ->assertSee(e(route('invoices.edit', [
                'invoice' => $invoice,
                'return_to' => 'invoices',
                'return_query' => $returnQuery,
            ])), false)
            ->assertSee(e(route('invoices.corrections.create', [
                'invoice' => $invoice,
                'series_id' => $correctionSeries->getKey(),
                'return_to' => 'invoices',
                'return_query' => $returnQuery,
            ])), false)
            ->assertSee('name="return_to" value="invoices"', false)
            ->assertSee('name="return_query" value="'.e($returnQuery).'"', false);

        $this->assertMatchesRegularExpression(
            '/<form\b[^>]*action="'.preg_quote(route('invoices.destroy', $invoice), '/').'"[^>]*>.*?name="return_to" value="invoices".*?name="return_query" value="'.preg_quote(e($returnQuery), '/').'".*?<\/form>/s',
            $response->getContent(),
        );
    }

    public function test_proforma_tab_shows_only_issued_proformas_with_pdf_and_shared_list_controls(): void
    {
        $invoiceOrder = $this->createDocumentOrder(['billing_name' => 'Jan Faktura']);
        $this->createDocumentItem($invoiceOrder);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $invoiceOrder,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $firstOrder = $this->createDocumentOrder(['billing_name' => 'Pierwsza Proforma']);
        $this->createDocumentItem($firstOrder);
        $first = app(ProformaService::class)->createOrRefresh(
            $firstOrder,
            $series,
            $this->documentContext('2026-08-04 10:00:00'),
        )->invoice;
        app(InvoiceIssuingService::class)->issue(
            $firstOrder,
            $this->createDocumentSeries(),
            $this->documentContext('2026-08-04 11:00:00'),
        );

        $secondOrder = $this->createDocumentOrder(['billing_name' => 'Druga Proforma']);
        $this->createDocumentItem($secondOrder);
        $second = app(ProformaService::class)->createOrRefresh(
            $secondOrder,
            $series,
            $this->documentContext('2026-08-03 10:00:00'),
        )->invoice;

        $response = $this->get(route('invoices.proformas.index'));

        $response->assertOk()
            ->assertSeeInOrder([$second->number, $first->number])
            ->assertDontSee($invoice->number)
            ->assertSee(route('invoices.pdf', $first), false)
            ->assertSee(route('invoices.proformas.bulk-pdf'), false)
            ->assertSee(route('invoices.proformas.bulk-delete'), false)
            ->assertSee(route('invoices.destroy', $first), false)
            ->assertDontSee(route('invoices.edit', $first), false)
            ->assertDontSee('KOREKTA')
            ->assertSee('ZAZNACZ WSZYSTKO')
            ->assertSee('DRUKUJ ZAZNACZONE')
            ->assertSee('USUŃ ZAZNACZONE')
            ->assertSee('SORTOWANIE');

        $html = $response->getContent();
        $this->assertUsesJsonBulkSelection(
            $html,
            $first,
            route('invoices.proformas.bulk-pdf'),
            route('invoices.proformas.bulk-delete'),
        );
        $this->assertMatchesRegularExpression(
            '/<form\b[^>]*action="'.preg_quote(route('invoices.destroy', $first), '/').'"[^>]*>/',
            $html,
        );
        $this->assertStringContainsString(
            'data-delete-blocked-message="Do Pro Forma została już wystawiona Faktura VAT."',
            $html,
        );
        $this->assertStringContainsString('window.nexOmsShowError(message)', $html);
        foreach (['invoice_series_id', 'invoice_month', 'invoice_year'] as $fieldId) {
            $this->assertMatchesRegularExpression(
                '/<select\b[^>]*id="'.preg_quote($fieldId, '/').'"[^>]*data-auto-submit-filter[^>]*>/',
                $html,
            );
        }
        $this->assertMatchesRegularExpression('/name="sort" value="number"/', $html);

        $this->get(route('invoices.proformas.index', ['month' => 8, 'year' => 2026, 'buyer' => 'Pierwsza']))
            ->assertOk()
            ->assertSee($first->number)
            ->assertDontSee($second->number);
    }

    public function test_list_filters_and_sorts_invoices_using_snapshot_data(): void
    {
        $series = $this->createDocumentSeries();
        $olderOrder = $this->createDocumentOrder([
            'billing_name' => 'Zofia Starsza',
            'billing_company_name' => null,
            'billing_tax_id' => '1111111111',
        ]);
        $this->createDocumentItem($olderOrder, ['unit_price_gross' => '50.00', 'total_price_gross' => '50.00']);
        $older = app(InvoiceIssuingService::class)->issue(
            $olderOrder,
            $series,
            $this->documentContext('2026-07-10 10:00:00'),
        );

        $newerOrder = $this->createDocumentOrder([
            'billing_name' => 'Anna Nowsza',
            'billing_company_name' => null,
            'billing_tax_id' => '2222222222',
        ]);
        $this->createDocumentItem($newerOrder, ['unit_price_gross' => '200.00', 'total_price_gross' => '200.00']);
        $newer = app(InvoiceIssuingService::class)->issue(
            $newerOrder,
            $series,
            $this->documentContext('2026-08-02 10:00:00'),
        );

        $this->get(route('invoices.index', ['buyer' => 'Zofia']))
            ->assertOk()
            ->assertSee($older->number)
            ->assertDontSee($newer->number);

        $this->get(route('invoices.index', ['month' => 8, 'year' => 2026]))
            ->assertOk()
            ->assertSee($newer->number)
            ->assertDontSee($older->number);

        $this->get(route('invoices.index', ['sort' => 'gross', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder([$older->number, $newer->number]);
    }

    public function test_document_lists_format_positive_and_negative_money_without_float(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $invoice->update(['total_gross' => '1234.56']);

        $correctionSeries = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        Invoice::query()->create([
            'order_id' => $order->getKey(),
            'invoice_series_id' => $correctionSeries->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'KOR LISTA 1/2026',
            'sequence_number' => 1,
            'numbering_period_key' => '2026',
            'issue_date' => '2026-08-02',
            'sale_date' => '2026-08-01',
            'issued_at' => '2026-08-02 10:00:00',
            'currency' => 'EUR',
            'total_gross' => '-108.55',
            'lock_version' => 1,
        ]);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('1 234,56 PLN');

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee('-108,55 EUR');
    }

    public function test_list_defaults_to_invoice_number_descending_and_quick_selects_submit_filters(): void
    {
        $series = $this->createDocumentSeries();

        foreach ([
            ['sequence' => 1, 'date' => '2026-08-05'],
            ['sequence' => 2, 'date' => '2026-08-04'],
        ] as $data) {
            Invoice::query()->create([
                'invoice_series_id' => $series->getKey(),
                'document_type' => InvoiceDocumentType::Invoice,
                'status' => InvoiceDocumentStatus::Issued,
                'number' => 'DOMYSLNA '.$data['sequence'].'/2026',
                'sequence_number' => $data['sequence'],
                'numbering_period_key' => '2026',
                'issue_date' => $data['date'],
                'sale_date' => $data['date'],
                'issued_at' => $data['date'].' 10:00:00',
                'currency' => 'PLN',
                'lock_version' => 1,
            ]);
        }

        $response = $this->get(route('invoices.index'));

        $response->assertOk()
            ->assertSeeInOrder(['DOMYSLNA 2/2026', 'DOMYSLNA 1/2026']);

        $html = $response->getContent();
        foreach (['invoice_series_id', 'invoice_month', 'invoice_year'] as $fieldId) {
            $this->assertMatchesRegularExpression(
                '/<select\b[^>]*id="'.preg_quote($fieldId, '/').'"[^>]*data-auto-submit-filter[^>]*>/',
                $html,
            );
        }
        $this->assertStringContainsString("filter.addEventListener('change', () => filter.form?.requestSubmit())", $html);
        $this->assertMatchesRegularExpression('/name="sort" value="number"/', $html);
    }

    public function test_list_paginates_twenty_five_invoices_and_keeps_query_parameters(): void
    {
        $series = $this->createDocumentSeries();

        foreach (range(1, 26) as $sequence) {
            Invoice::query()->create([
                'invoice_series_id' => $series->getKey(),
                'document_type' => InvoiceDocumentType::Invoice,
                'status' => InvoiceDocumentStatus::Issued,
                'number' => 'LISTA '.$sequence.'/2026',
                'sequence_number' => $sequence,
                'numbering_period_key' => '2026',
                'issue_date' => '2026-08-01',
                'sale_date' => '2026-08-01',
                'issued_at' => '2026-08-01 10:00:00',
                'currency' => 'PLN',
                'lock_version' => 1,
            ]);
        }

        $response = $this->get(route('invoices.index', [
            'year' => 2026,
            'sort' => 'number',
            'direction' => 'asc',
        ]));

        $response->assertOk();
        $this->assertCount(25, $response->viewData('invoices'));
        $this->assertSame([25, 50, 75, 100, 150, 200, 300, 500, 1000], $response->viewData('perPageOptions'));
        $this->assertStringContainsString('year=2026', $response->viewData('invoices')->nextPageUrl());

        $largerPage = $this->get(route('invoices.index', ['year' => 2026, 'per_page' => 1000]));
        $largerPage->assertOk();
        $this->assertSame(1000, $largerPage->viewData('perPage'));
        $this->assertCount(26, $largerPage->viewData('invoices'));

        $secondPage = $this->get(route('invoices.index', ['year' => 2026, 'page' => 2]));
        $secondPage->assertOk();
        $this->assertCount(1, $secondPage->viewData('invoices'));
    }

    public function test_invoice_list_eager_loads_and_shows_latest_ksef_status_without_lazy_queries(): void
    {
        $invoices = collect(['Pierwsza', 'Druga', 'Trzecia'])->map(function (string $buyer): Invoice {
            $order = $this->createDocumentOrder(['billing_name' => $buyer]);
            $this->createDocumentItem($order);

            return app(InvoiceIssuingService::class)->issue(
                $order,
                $this->createDocumentSeries(),
                $this->documentContext(),
            );
        });
        $this->createListSubmission($invoices[0], KsefInvoiceSubmissionStatus::Rejected, 1);
        $this->createListSubmission($invoices[0], KsefInvoiceSubmissionStatus::Accepted, 2);
        $this->createListSubmission($invoices[1], KsefInvoiceSubmissionStatus::Processing, 1);

        Model::preventLazyLoading();

        try {
            $response = $this->get(route('invoices.index'));
        } finally {
            Model::preventLazyLoading(false);
        }

        $response->assertOk()
            ->assertSee('KSeF')
            ->assertSee('Przyjęta')
            ->assertSee('Przetwarzanie')
            ->assertSee('Nie wysłano');
        $response->viewData('invoices')->getCollection()->each(
            fn (Invoice $invoice) => $this->assertTrue($invoice->relationLoaded('latestKsefSubmission')),
        );
        $listedInvoice = $response->viewData('invoices')->getCollection()->firstWhere('id', $invoices[0]->getKey());
        $latestSubmission = $listedInvoice->latestKsefSubmission;

        $this->assertNotNull($latestSubmission);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $latestSubmission->status);
        $loadedAttributeNames = array_keys($latestSubmission->getAttributes());
        sort($loadedAttributeNames);
        $this->assertSame(['environment', 'id', 'invoice_id', 'status'], $loadedAttributeNames);
        $this->assertArrayNotHasKey('payload_xml', $latestSubmission->getAttributes());
        $this->assertArrayNotHasKey('invoice_hash', $latestSubmission->getAttributes());
        $this->assertArrayNotHasKey('context_nip', $latestSubmission->getAttributes());
        $this->assertArrayNotHasKey('seller_nip', $latestSubmission->getAttributes());
    }

    private function createListSubmission(
        Invoice $invoice,
        KsefInvoiceSubmissionStatus $status,
        int $attemptNumber,
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>LIST '.$attemptNumber.'</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now(),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
        ]);
    }

    private function assertUsesJsonBulkSelection(
        string $html,
        Invoice $document,
        string $printRoute,
        string $deleteRoute,
    ): void {
        $this->assertStringContainsString(
            '<form id="bulkInvoicePrintForm" method="POST" action="'.$printRoute.'" target="_blank">',
            $html,
        );
        $this->assertStringContainsString(
            '<form id="bulkInvoiceDeleteForm" method="POST" action="'.$deleteRoute.'">',
            $html,
        );
        $this->assertSame(2, substr_count($html, 'name="selection"'));
        $this->assertStringContainsString('data-bulk-print-selection', $html);
        $this->assertStringContainsString('data-bulk-delete-selection', $html);
        $checkboxPattern = '/<input\b(?=[^>]*data-invoice-checkbox)'
            .'(?=[^>]*data-invoice-id="'.preg_quote((string) $document->getKey(), '/').'")'
            .'(?=[^>]*data-lock-version="'.preg_quote((string) $document->lock_version, '/').'")[^>]*>/';
        $this->assertMatchesRegularExpression($checkboxPattern, $html);
        $this->assertStringNotContainsString('name="invoice_ids[]"', $html);
        $this->assertStringNotContainsString('name="lock_versions[', $html);
        $this->assertStringNotContainsString('formaction=', $html);
        $this->assertStringContainsString(
            'const selectedCheckboxes = () => checkboxes.filter((checkbox) => checkbox.checked);',
            $html,
        );
        $this->assertStringContainsString('printSelection.value = JSON.stringify(', $html);
        $this->assertStringContainsString('deleteSelection.value = JSON.stringify(Object.fromEntries(', $html);
        $this->assertStringNotContainsString('slice(0, 100)', $html);
        $this->assertStringNotContainsString('selected.length > 100', $html);
    }
}
