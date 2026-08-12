<?php

namespace Tests\Feature\Invoices;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\InvoiceCurrencyConversionService;
use Modules\Invoices\Services\InvoiceDocumentPreparationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceNumberingService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceIssuingServiceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_invoice_is_issued_with_number_snapshots_items_slot_and_event(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());

        $this->assertSame(InvoiceDocumentType::Invoice, $invoice->document_type);
        $this->assertSame(InvoiceDocumentStatus::Issued, $invoice->status);
        $this->assertSame(1, $invoice->sequence_number);
        $this->assertSame('2026', $invoice->numbering_period_key);
        $this->assertSame('2026-07-28', $invoice->issue_date->toDateString());
        $this->assertSame('2026-07-28 12:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));
        $this->assertCount(2, $invoice->items);
        $this->assertSame('123.00', $invoice->total_gross);
        $this->assertSame('NEX Seller sp. z o.o.', $invoice->seller_snapshot['name']);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $order->getKey(),
            'document_type' => 'invoice',
            'invoice_id' => $invoice->getKey(),
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'invoice_issued',
            'title' => 'Wystawiono fakturę',
        ]);
    }

    public function test_second_invoice_is_rejected_without_advancing_counter_or_creating_event(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $service = app(InvoiceIssuingService::class);
        $service->issue($order, $series, $this->documentContext());
        $counterBefore = InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number;

        try {
            $service->issue($order, $series, $this->documentContext('2026-07-28 13:00:00'));
            $this->fail('Utworzono drugą fakturę VAT.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_already_exists', $exception->errorCode());
            $this->assertSame(
                'Nie można wystawić faktury VAT. Faktura do tego zamówienia została już wystawiona.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, Invoice::query()->where('document_type', 'invoice')->count());
        $this->assertSame(1, OrderDocumentSlot::query()->where('document_type', 'invoice')->count());
        $this->assertSame(1, $order->events()->where('event_type', 'invoice_issued')->count());
        $this->assertSame($counterBefore, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_existing_invoice_without_slot_still_returns_duplicate_and_is_not_repaired(): void
    {
        Log::spy();
        $order = $this->createDocumentOrder();
        $series = $this->createDocumentSeries();
        Invoice::query()->create([
            'order_id' => $order->getKey(),
            'invoice_series_id' => $series->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
        ]);

        try {
            app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
            $this->fail('Istniejąca faktura bez slotu została zduplikowana.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_already_exists', $exception->errorCode());
        }

        $this->assertDatabaseCount('order_document_slots', 0);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_orphaned_invoice_slot_is_reported_as_inconsistent(): void
    {
        $order = $this->createDocumentOrder();
        $series = $this->createDocumentSeries();
        OrderDocumentSlot::query()->create([
            'order_id' => $order->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
        ]);

        try {
            app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
            $this->fail('Niespójny slot został pominięty.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_document_slot_inconsistent', $exception->errorCode());
        }

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_inactive_and_wrong_type_series_are_rejected(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $inactive = $this->createDocumentSeries(attributes: ['is_active' => false]);
        $proforma = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $service = app(InvoiceIssuingService::class);

        foreach ([
            [$inactive, 'invoice_series_inactive'],
            [$proforma, 'invoice_series_type_mismatch'],
        ] as [$series, $expectedCode]) {
            try {
                $service->issue($order, $series, $this->documentContext());
                $this->fail('Nieprawidłowa seria wystawiła fakturę.');
            } catch (InvoiceDomainException $exception) {
                $this->assertSame($expectedCode, $exception->errorCode());
            }
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_number_counters', 0);
    }

    public function test_error_after_number_assignment_rolls_back_document_items_slot_counter_and_event(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $service = new class(app(InvoiceDocumentPreparationService::class), app(InvoiceNumberingService::class), app(InvoiceCurrencyConversionService::class)) extends InvoiceIssuingService
        {
            protected function afterNumberAssigned(Invoice $invoice): void
            {
                throw new DomainException('Wymuszony błąd po numeracji.');
            }
        };

        try {
            $service->issue($order, $series, $this->documentContext());
            $this->fail('Operacja z wymuszonym błędem nie została wycofana.');
        } catch (DomainException $exception) {
            $this->assertSame('Wymuszony błąd po numeracji.', $exception->getMessage());
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('order_document_slots', 0);
        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('order_events', 0);
    }

    public function test_financial_storage_violation_does_not_create_invoice_or_consume_number(): void
    {
        $order = $this->createDocumentOrder(['delivery_cost_gross' => '0.00']);
        $item = $this->createDocumentItem($order, [
            'unit_price_gross' => '9999999999.99',
            'total_price_gross' => '10000000000.00',
        ]);
        $series = $this->createDocumentSeries();

        try {
            app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
            $this->fail('Wystawiono Fakturę z wartością przekraczającą zakres zamówienia.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_financial_value_out_of_range', $exception->errorCode());
        }

        foreach (['invoices', 'invoice_items', 'order_document_slots', 'invoice_number_counters', 'order_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $item->update(['total_price_gross' => '9999999999.99']);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());

        $this->assertSame(1, $invoice->sequence_number);
        $this->assertSame(1, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_slot_database_constraints_protect_document_types(): void
    {
        $order = $this->createDocumentOrder();
        OrderDocumentSlot::query()->create([
            'order_id' => $order->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
        ]);
        OrderDocumentSlot::query()->create([
            'order_id' => $order->getKey(),
            'document_type' => InvoiceDocumentType::Proforma,
        ]);

        $this->assertDatabaseCount('order_document_slots', 2);

        $this->expectException(QueryException::class);
        OrderDocumentSlot::query()->create([
            'order_id' => $order->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
        ]);
    }
}
