<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceItemBuilder;
use Modules\Invoices\Services\ProformaService;
use Modules\Products\Models\Product;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceStage2DPreparationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_zero_cost_shipping_is_preserved_when_method_is_known_and_enabled(): void
    {
        $order = $this->createDocumentOrder(['delivery_cost_gross' => '0.00']);
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        $items = app(InvoiceItemBuilder::class)->build($order, $series);

        $this->assertCount(2, $items);
        $this->assertSame('shipping', $items[1]['line_type']);
        $this->assertSame('1.0000', $items[1]['quantity']);
        $this->assertSame('23.00', $items[1]['vat_rate']);
        $this->assertSame('0.00', $items[1]['total_net']);
        $this->assertSame('0.00', $items[1]['total_vat']);
        $this->assertSame('0.00', $items[1]['total_gross']);
    }

    public function test_shipping_is_omitted_without_method_or_when_disabled(): void
    {
        $order = $this->createDocumentOrder(['delivery_cost_gross' => '0.00', 'shipping_method' => null]);
        $this->createDocumentItem($order);

        $this->assertCount(1, app(InvoiceItemBuilder::class)->build($order, $this->createDocumentSeries()));

        $order->update(['shipping_method' => 'Kurier']);
        $disabled = $this->createDocumentSeries(attributes: ['include_shipping' => false]);
        $this->assertCount(1, app(InvoiceItemBuilder::class)->build($order, $disabled));
    }

    public function test_catalog_product_identity_is_copied_to_the_invoice_item_snapshot(): void
    {
        $order = $this->createDocumentOrder([
            'delivery_cost_gross' => '0.00',
            'shipping_method' => null,
        ]);
        $product = Product::query()->create([
            'name' => 'Produkt katalogowy',
            'ean' => '5901234567890',
            'price_gross' => '123.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $this->createDocumentItem($order, [
            'product_id' => $product->getKey(),
            'product_name' => 'Snapshot nazwy produktu',
            'ean' => $product->ean,
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
        ]);

        $items = app(InvoiceItemBuilder::class)->build($order, $this->createDocumentSeries());

        $this->assertCount(1, $items);
        $this->assertSame($product->getKey(), $items[0]['product_id']);
        $this->assertSame($product->getKey(), $items[0]['product_snapshot']['internal_id']);
        $this->assertSame('Snapshot nazwy produktu', $items[0]['product_snapshot']['name']);
    }

    public function test_invoice_snapshots_existing_related_proforma(): void
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

        $snapshot = $invoice->order_snapshot['related_documents']['proforma'];
        $this->assertSame($proforma->getKey(), $snapshot['invoice_id']);
        $this->assertSame($proforma->number, $snapshot['number']);
        $this->assertArrayNotHasKey('revision_number', $snapshot);
        $this->assertSame($proforma->issue_date->toDateString(), $snapshot['issue_date']);

        $proforma->update([
            'number' => 'PF ZMIENIONA',
            'lock_version' => 99,
            'issue_date' => '2027-01-01',
        ]);

        $this->assertSame($snapshot, $invoice->refresh()->order_snapshot['related_documents']['proforma']);
    }
}
