<?php

namespace Tests\Feature\Invoices;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\InvoiceRevision;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceRevisionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_revision_is_immutable_and_relation_works(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoice = app(ProformaService::class)
            ->createOrRefresh($order, $series, $this->documentContext())
            ->invoice;
        $revision = $invoice->revisions()->firstOrFail();

        $this->assertTrue($revision->invoice->is($invoice));
        $this->assertSame(1, $revision->revision_number);

        try {
            $revision->update(['source_snapshot_hash' => str_repeat('a', 64)]);
            $this->fail('Historyczna rewizja została zmieniona.');
        } catch (DomainException) {
            $this->assertNotSame(str_repeat('a', 64), $revision->refresh()->source_snapshot_hash);
        }

        $this->expectException(DomainException::class);
        $revision->delete();
    }

    public function test_revision_number_is_unique_inside_one_invoice(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoice = app(ProformaService::class)
            ->createOrRefresh($order, $series, $this->documentContext())
            ->invoice;
        $revision = $invoice->revisions()->firstOrFail();

        $this->expectException(QueryException::class);
        InvoiceRevision::query()->create([
            'invoice_id' => $invoice->getKey(),
            'revision_number' => 1,
            'document_snapshot' => $revision->document_snapshot,
            'items_snapshot' => $revision->items_snapshot,
            'source_snapshot_hash' => $revision->source_snapshot_hash,
            'source' => $revision->source,
        ]);
    }
}
