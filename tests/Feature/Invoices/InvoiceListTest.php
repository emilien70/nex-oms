<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\ProformaService;
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
            ->assertSee(route('invoices.edit', $invoice), false)
            ->assertSee(route('invoices.destroy', $invoice), false)
            ->assertSee(route('invoices.bulk-pdf'), false);
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
        $this->assertStringContainsString('year=2026', $response->viewData('invoices')->nextPageUrl());

        $secondPage = $this->get(route('invoices.index', ['year' => 2026, 'page' => 2]));
        $secondPage->assertOk();
        $this->assertCount(1, $secondPage->viewData('invoices'));
    }
}
