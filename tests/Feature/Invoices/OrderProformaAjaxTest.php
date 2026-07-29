<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderProformaAjaxTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_proforma_is_created_through_ajax_and_keeps_invoice_action(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoiceSeries = $this->createDocumentSeries();
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);

        $response = $this->postJson(route('orders.proforma.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ])->assertCreated()
            ->assertJsonPath('document.type', 'proforma')
            ->assertJsonStructure(['html', 'document' => ['id', 'type', 'number', 'pdf_url']]);

        $html = $response->json('html');
        $this->assertStringContainsString($response->json('document.number'), $html);
        $this->assertStringContainsString('WYSTAW FAKTUR', $html);
        $this->assertStringContainsString((string) $invoiceSeries->getKey(), $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringNotContainsString('Wersja', $html);
        $response->assertSessionMissing('success');
        $response->assertJsonMissingPath('message');
    }

    public function test_existing_proforma_is_refreshed_without_changing_identity_or_number(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);

        $first = $this->postJson(route('orders.proforma.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ])->assertCreated();
        $invoiceId = $first->json('document.id');

        $order->update(['notes' => 'Odświeżone dane']);
        $second = $this->postJson(route('orders.proforma.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ])->assertOk();

        $second->assertJsonPath('document.id', $invoiceId)
            ->assertJsonPath('document.number', $first->json('document.number'));
        $this->assertSame(2, Invoice::query()->findOrFail($invoiceId)->revision_number);
        $this->assertSame(1, Invoice::query()->where('document_type', 'proforma')->count());
        $this->assertStringNotContainsString('Wersja', $second->json('html'));
    }

    public function test_proforma_backend_rejects_inactive_and_wrong_type_series(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        $inactive = $this->createDocumentSeries(
            InvoiceDocumentType::Proforma,
            ['is_active' => false],
        );
        $this->postJson(route('orders.proforma.store', $order), [
            'invoice_series_id' => $inactive->getKey(),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_series_inactive');

        $wrongType = $this->createDocumentSeries();
        $this->postJson(route('orders.proforma.store', $order), [
            'invoice_series_id' => $wrongType->getKey(),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_series_type_mismatch');
    }
}
