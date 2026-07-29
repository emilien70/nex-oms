<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderInvoiceAjaxTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_invoice_is_issued_through_ajax_and_returns_updated_fragment(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        $response = $this->postJson(route('orders.invoice.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('document.type', 'invoice')
            ->assertJsonPath('document.number', 'FV 1/2026')
            ->assertJsonStructure(['html', 'document' => ['id', 'type', 'number', 'pdf_url']]);
        $this->assertStringContainsString('id="order-sales-document-actions"', $response->json('html'));
        $this->assertStringContainsString('FV 1/2026', $response->json('html'));
        $this->assertStringContainsString('target="_blank"', $response->json('html'));
        $this->assertStringContainsString('rel="noopener"', $response->json('html'));
        $this->assertStringNotContainsString('WYSTAW FAKTUR', $response->json('html'));
        $this->assertStringNotContainsString('PRO FORMA', $response->json('html'));
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->getKey(),
            'document_type' => 'invoice',
            'status' => 'issued',
        ]);
        $response->assertSessionMissing('success');
        $response->assertJsonMissingPath('message');
    }

    public function test_invoice_backend_rejects_invalid_inactive_and_wrong_type_series(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        $this->postJson(route('orders.invoice.store', $order), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice_series_id');

        $inactive = $this->createDocumentSeries(attributes: ['is_active' => false]);
        $this->postJson(route('orders.invoice.store', $order), [
            'invoice_series_id' => $inactive->getKey(),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_series_inactive')
            ->assertJsonPath('message', 'Nie można wystawić dokumentu, ponieważ wybrana seria numeracji jest ukryta.');

        $wrongType = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $this->postJson(route('orders.invoice.store', $order), [
            'invoice_series_id' => $wrongType->getKey(),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_series_type_mismatch');
    }

    public function test_duplicate_invoice_returns_stable_conflict_without_sql_details(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        $this->postJson(route('orders.invoice.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ])->assertCreated();

        $response = $this->postJson(route('orders.invoice.store', $order), [
            'invoice_series_id' => $series->getKey(),
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_already_exists')
            ->assertJsonPath(
                'message',
                'Nie można wystawić faktury VAT. Faktura do tego zamówienia została już wystawiona.',
            );

        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertStringNotContainsString('constraint', strtolower($response->getContent()));
    }
}
