<?php

namespace Tests\Feature\Invoices;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\ProformaOperationStatus;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Services\InvoiceDocumentPreparationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceNumberingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Invoices\Services\ProformaService;
use Modules\Invoices\Services\ProformaSourceSnapshotHasher;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class ProformaServiceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_first_call_creates_one_numbered_proforma_slot_and_event(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);

        $result = app(ProformaService::class)->createOrRefresh($order, $series, $this->documentContext());
        $invoice = $result->invoice;

        $this->assertSame(ProformaOperationStatus::Created, $result->status);
        $this->assertSame(InvoiceDocumentType::Proforma, $invoice->document_type);
        $this->assertSame(InvoiceDocumentStatus::Issued, $invoice->status);
        $this->assertSame(1, $invoice->lock_version);
        $this->assertSame(1, $invoice->sequence_number);
        $this->assertSame(64, strlen((string) $invoice->source_snapshot_hash));
        $this->assertNull($invoice->last_refreshed_at);
        $this->assertCount(2, $invoice->items);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $order->getKey(),
            'document_type' => 'proforma',
            'invoice_id' => $invoice->getKey(),
        ]);
        $this->assertFalse(Schema::hasTable('invoice_revisions'));
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'proforma_issued',
        ]);
    }

    public function test_unchanged_call_does_not_write_anything_or_advance_numbering(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $created = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;
        $originalUpdatedAt = $created->updated_at->format('Y-m-d H:i:s.u');
        $itemIds = $created->items()->pluck('id')->all();
        $counter = InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number;

        $order->touch();
        $result = $service->createOrRefresh($order->refresh(), $series, $this->documentContext('2026-07-29 08:00:00'));

        $this->assertSame(ProformaOperationStatus::Unchanged, $result->status);
        $this->assertSame($created->getKey(), $result->invoice->getKey());
        $this->assertSame(1, $result->invoice->lock_version);
        $this->assertNull($result->invoice->last_refreshed_at);
        $this->assertSame($originalUpdatedAt, $result->invoice->updated_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($itemIds, $result->invoice->items()->pluck('id')->all());
        $this->assertSame(0, $order->events()->where('event_type', 'proforma_refreshed')->count());
        $this->assertSame($counter, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_changed_order_refreshes_same_proforma_and_preserves_numbering_identity(): void
    {
        $order = $this->createDocumentOrder();
        $item = $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $created = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;
        $identity = $created->only([
            'id', 'invoice_series_id', 'number', 'sequence_number', 'numbering_period_key',
            'number_format_snapshot', 'issue_date', 'issued_at',
        ]);
        $oldHash = $created->source_snapshot_hash;

        $item->update([
            'unit_price_gross' => '200.00',
            'total_price_gross' => '200.00',
        ]);
        $order->update(['notes' => 'Nowa treść', 'total_gross' => '223.00']);
        $result = $service->createOrRefresh($order->refresh(), $series, $this->documentContext('2026-07-30 09:00:00'));
        $refreshed = $result->invoice;

        $this->assertSame(ProformaOperationStatus::Refreshed, $result->status);
        $this->assertSame($identity['id'], $refreshed->getKey());
        $this->assertSame($identity['invoice_series_id'], $refreshed->invoice_series_id);
        $this->assertSame($identity['number'], $refreshed->number);
        $this->assertSame($identity['sequence_number'], $refreshed->sequence_number);
        $this->assertSame($identity['numbering_period_key'], $refreshed->numbering_period_key);
        $this->assertSame($identity['number_format_snapshot'], $refreshed->number_format_snapshot);
        $this->assertSame($identity['issue_date']->toDateString(), $refreshed->issue_date->toDateString());
        $this->assertSame($identity['issued_at']->toIso8601String(), $refreshed->issued_at->toIso8601String());
        $this->assertSame(2, $refreshed->lock_version);
        $this->assertNotSame($oldHash, $refreshed->source_snapshot_hash);
        $this->assertSame('2026-07-30 09:00:00', $refreshed->last_refreshed_at->format('Y-m-d H:i:s'));
        $this->assertSame('200.00', $refreshed->items->first()->total_gross);
        $this->assertSame(1, $order->events()->where('event_type', 'proforma_refreshed')->count());
        $this->assertSame(1, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_refresh_invalidates_only_current_pdf_cache_after_a_real_change(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $item = $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $proforma = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($proforma);
        Storage::disk('local')->put($path, '%PDF-1.7 current proforma');

        $unchanged = $service->createOrRefresh($order->refresh(), $series, $this->documentContext());

        $this->assertSame(ProformaOperationStatus::Unchanged, $unchanged->status);
        Storage::disk('local')->assertExists($path);

        $item->update([
            'unit_price_gross' => '200.00',
            'total_price_gross' => '200.00',
        ]);
        $order->update(['total_gross' => '223.00']);

        $refreshed = $service->createOrRefresh($order->refresh(), $series, $this->documentContext());

        $this->assertSame(ProformaOperationStatus::Refreshed, $refreshed->status);
        $this->assertSame(2, $refreshed->invoice->lock_version);
        Storage::disk('local')->assertMissing($path);

        $this->get(route('invoices.pdf', $refreshed->invoice))->assertOk();
        Storage::disk('local')->assertExists($path);
        $this->assertSame([$path], Storage::disk('local')->allFiles('invoices/'.$proforma->getKey()));
    }

    public function test_financial_storage_violation_rolls_back_proforma_refresh_and_keeps_pdf_cache(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $item = $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $proforma = $service->createOrRefresh($order, $series, $this->documentContext())->invoice;
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($proforma);
        Storage::disk('local')->put($path, '%PDF-1.7 current proforma');
        $beforeAttributes = $proforma->only([
            'lock_version',
            'source_snapshot_hash',
            'seller_snapshot',
            'buyer_snapshot',
            'order_snapshot',
            'total_net',
            'total_vat',
            'total_gross',
        ]);
        $beforeItemIds = $proforma->items()->orderBy('id')->pluck('id')->all();

        $item->update(['total_price_gross' => '10000000000.00']);

        try {
            $service->createOrRefresh($order->refresh(), $series, $this->documentContext('2026-07-30 10:00:00'));
            $this->fail('Odświeżono Pro formę z wartością poza zakresem storage.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_financial_value_out_of_range', $exception->errorCode());
        }

        $proforma->refresh();
        $this->assertSame($beforeAttributes, $proforma->only(array_keys($beforeAttributes)));
        $this->assertSame($beforeItemIds, $proforma->items()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, $order->events()->where('event_type', 'proforma_refreshed')->count());
        $this->assertSame(1, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
        Storage::disk('local')->assertExists($path);
    }

    public function test_existing_proforma_cannot_change_series_and_hidden_original_series_blocks_refresh(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $other = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(ProformaService::class);
        $service->createOrRefresh($order, $series, $this->documentContext());

        try {
            $service->createOrRefresh($order, $other, $this->documentContext());
            $this->fail('Zmieniono serię istniejącej Pro formy.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('proforma_series_cannot_change', $exception->errorCode());
        }

        $series->update(['is_active' => false]);

        try {
            $service->createOrRefresh($order, $series->refresh(), $this->documentContext());
            $this->fail('Odświeżono Pro formę z ukrytej serii.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('proforma_series_inactive', $exception->errorCode());
        }
    }

    public function test_existing_proforma_without_slot_is_reported_as_inconsistent_and_not_duplicated(): void
    {
        $order = $this->createDocumentOrder();
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        Invoice::query()->create([
            'order_id' => $order->getKey(),
            'invoice_series_id' => $series->getKey(),
            'document_type' => InvoiceDocumentType::Proforma,
        ]);

        try {
            app(ProformaService::class)->createOrRefresh($order, $series, $this->documentContext());
            $this->fail('Zduplikowano Pro formę bez slotu.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_document_slot_inconsistent', $exception->errorCode());
        }

        $this->assertSame(1, Invoice::query()->where('document_type', 'proforma')->count());
        $this->assertDatabaseCount('order_document_slots', 0);
    }

    public function test_vat_invoice_supersedes_proforma_without_changing_its_current_state(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proformaService = app(ProformaService::class);
        $proforma = $proformaService->createOrRefresh($order, $proformaSeries, $this->documentContext())->invoice;
        $number = $proforma->number;
        $items = $proforma->items()->get()->toArray();

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $invoiceSeries,
            $this->documentContext('2026-07-29 10:00:00'),
        );
        $proforma->refresh();

        $this->assertTrue($proforma->isProformaSuperseded());
        $this->assertSame($invoice->getKey(), $proforma->superseded_by_invoice_id);
        $this->assertSame($number, $proforma->number);
        $this->assertSame(1, $proforma->lock_version);
        $this->assertSame($items, $proforma->items()->get()->toArray());
        $event = $order->events()->where('event_type', 'invoice_issued')->firstOrFail();
        $this->assertSame($proforma->getKey(), $event->payload['superseded_proforma_id']);
        $this->assertSame($proforma->number, $event->payload['superseded_proforma_number']);
    }

    public function test_invoice_blocks_new_proforma_without_consuming_proforma_number(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoiceSeries = $this->createDocumentSeries();
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        app(InvoiceIssuingService::class)->issue($order, $invoiceSeries, $this->documentContext());

        try {
            app(ProformaService::class)->createOrRefresh($order, $proformaSeries, $this->documentContext());
            $this->fail('Utworzono Pro formę po Fakturze VAT.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('proforma_locked_by_invoice', $exception->errorCode());
        }

        $this->assertSame(0, Invoice::query()->where('document_type', 'proforma')->count());
        $this->assertSame(0, $proformaSeries->numberCounters()->count());
    }

    public function test_error_after_number_assignment_rolls_back_entire_first_proforma_operation(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = new class(app(InvoiceDocumentPreparationService::class), app(ProformaSourceSnapshotHasher::class), app(InvoiceNumberingService::class), app(InvoicePdfStorage::class)) extends ProformaService
        {
            protected function afterNumberAssigned(Invoice $invoice): void
            {
                throw new DomainException('Wymuszony błąd Pro formy.');
            }
        };

        try {
            $service->createOrRefresh($order, $series, $this->documentContext());
            $this->fail('Nie wycofano nieudanej Pro formy.');
        } catch (DomainException $exception) {
            $this->assertSame('Wymuszony błąd Pro formy.', $exception->getMessage());
        }

        foreach (['invoices', 'invoice_items', 'order_document_slots', 'invoice_number_counters', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
