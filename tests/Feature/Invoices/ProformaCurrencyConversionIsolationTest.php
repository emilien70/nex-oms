<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\ProformaOperationStatus;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class ProformaCurrencyConversionIsolationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_foreign_proforma_creation_and_refresh_never_call_nbp_or_store_pln_conversion(): void
    {
        Http::preventStrayRequests();
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $item = $this->createDocumentItem($order, ['currency' => 'EUR']);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);

        $created = $service->createOrRefresh($order, $series, $this->documentContext());
        $this->assertSame(ProformaOperationStatus::Created, $created->status);
        $this->assertSame([], $created->invoice->tax_metadata_snapshot);
        $number = $created->invoice->number;
        $hash = $created->invoice->source_snapshot_hash;

        $item->update(['unit_price_gross' => '101.00', 'total_price_gross' => '101.00']);
        $refreshed = $service->createOrRefresh(
            $order->refresh(),
            $series,
            $this->documentContext('2026-07-29 10:00:00'),
        );

        $this->assertSame(ProformaOperationStatus::Refreshed, $refreshed->status);
        $this->assertSame([], $refreshed->invoice->tax_metadata_snapshot);
        $this->assertSame($number, $refreshed->invoice->number);
        $this->assertNotSame($hash, $refreshed->invoice->source_snapshot_hash);
        $this->assertArrayNotHasKey('converted_tax_summary', $refreshed->invoice->tax_metadata_snapshot);
        Http::assertNothingSent();
    }

    public function test_nbp_availability_or_rate_changes_do_not_create_proforma_revision(): void
    {
        Http::preventStrayRequests();
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $created = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;

        Http::fake(['*' => Http::response('', 500)]);
        $unchanged = $service->createOrRefresh(
            $order->refresh(),
            $series,
            $this->documentContext('2026-07-29 10:00:00'),
        );

        $this->assertSame(ProformaOperationStatus::Unchanged, $unchanged->status);
        $this->assertSame(1, $unchanged->invoice->revision_number);
        $this->assertSame($created->number, $unchanged->invoice->number);
        $this->assertSame([], $unchanged->invoice->tax_metadata_snapshot);
        Http::assertNothingSent();
    }
}
