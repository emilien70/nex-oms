<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderSalesDocumentActionsTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_management_fragment_has_stable_container_and_existing_actions(): void
    {
        $order = $this->createDocumentOrder();
        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('id="order-sales-document-actions"', $html);
        $this->assertStringContainsString('WYSTAW FAKTUR', $html);
        $this->assertStringContainsString('PRO FORMA', $html);
        $this->assertStringContainsString('data-sales-document-form', $html);
        $this->assertStringNotContainsString('Wystawianie', $html);
        $this->assertStringNotContainsString('Tworzenie', $html);
        $this->assertStringNotContainsString('Brak aktywnej serii', $html);
    }

    public function test_single_active_series_uses_plain_button_and_filters_other_series(): void
    {
        $order = $this->createDocumentOrder();
        $invoiceSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Invoice)
            ->firstOrFail();
        $proformaSeries = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Proforma)
            ->firstOrFail();
        $hidden = $this->createDocumentSeries(attributes: [
            'name' => 'Ukryta seria faktur',
            'is_active' => false,
        ]);
        $otherType = $this->createDocumentSeries(InvoiceDocumentType::Correction, [
            'name' => 'Seria korekt niewidoczna w akcjach',
        ]);

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('value="'.$invoiceSeries->getKey().'"', $html);
        $this->assertStringContainsString('value="'.$proformaSeries->getKey().'"', $html);
        $this->assertStringNotContainsString('dropdown-toggle', $html);
        $this->assertStringNotContainsString($hidden->name, $html);
        $this->assertStringNotContainsString($otherType->name, $html);
    }

    public function test_multiple_active_invoice_series_are_presented_only_by_name(): void
    {
        $order = $this->createDocumentOrder();
        $first = $this->createDocumentSeries();
        $second = $this->createDocumentSeries(attributes: ['name' => 'Faktury dodatkowe']);
        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('dropdown-toggle', $html);
        $this->assertStringContainsString($first->name, $html);
        $this->assertStringContainsString($second->name, $html);
        $this->assertStringNotContainsString($first->number_format, $html);
        $this->assertStringNotContainsString('systemowa', $html);
    }

    public function test_order_page_contains_only_one_sales_document_actions_fragment(): void
    {
        $template = file_get_contents(resource_path('views/orders/show.blade.php'));

        $this->assertIsString($template);
        $this->assertStringContainsString('Zarz&#261;dzanie', $template);
        $this->assertSame(1, substr_count($template, "@include('orders.partials.sales-document-actions'"));
    }

    public function test_proforma_number_is_visible_until_invoice_is_issued(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $proformaSeries,
            $this->documentContext(),
        )->invoice;

        $before = app(OrderSalesDocumentActionsView::class)->render($order);
        $this->assertStringContainsString($proforma->number, $before);
        $this->assertStringContainsString('WYSTAW FAKTUR', $before);
        $this->assertStringContainsString('data-open-document-after-submit', $before);
        $this->assertStringContainsString(route('orders.proforma.store', $order), $before);
        $this->assertStringContainsString('value="'.$proforma->invoice_series_id.'"', $before);

        $invoice = app(InvoiceIssuingService::class)->issue($order, $invoiceSeries, $this->documentContext());
        $after = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString($invoice->number, $after);
        $this->assertStringNotContainsString($proforma->number, $after);
        $this->assertStringNotContainsString('PRO FORMA', $after);
    }

    public function test_issued_correction_is_presented_with_invoice_style_actions(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $correctionSeries = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        $correction = app(CorrectionService::class)->issue(
            $invoice,
            $correctionSeries,
            [
                'correction_series_id' => $correctionSeries->getKey(),
                'reason' => CorrectionReason::BuyerDataUpdate->value,
                'other_reason' => null,
                'issue_date' => '2026-08-05',
                'sale_date' => '2026-07-20',
                'payment_method' => 'Przelew',
                'issuer_name' => 'Tester korekty',
                'additional_information' => null,
                'change_items' => false,
                'change_buyer' => true,
                'items' => [],
                'buyer' => array_merge($invoice->buyer_snapshot, [
                    'name' => 'Jan Nowak',
                    'company_name' => null,
                ]),
            ],
            $this->documentContext('2026-08-05 10:00:00'),
        );

        $html = app(OrderSalesDocumentActionsView::class)->render($order);

        $this->assertStringContainsString('Korekta:', $html);
        $this->assertStringContainsString('management-issued-correction-actions', $html);
        $this->assertStringContainsString($correction->number, $html);
        $this->assertSame(2, substr_count($html, route('invoices.pdf', $correction)));
        $this->assertStringContainsString(route('invoices.corrections.edit', [
            'correction' => $correction,
            'return_to' => 'order',
        ]), $html);
        $this->assertStringContainsString('data-bs-target="#deleteCorrectionFromOrderModal"', $html);
        $this->assertStringContainsString(route('invoices.destroy', $correction), $html);
        $this->assertSame(2, substr_count($html, 'data-sales-document-number'));
    }
}
