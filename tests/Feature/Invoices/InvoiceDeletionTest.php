<?php

namespace Tests\Feature\Invoices;

use App\Models\Order;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\InvoiceDeletionPolicy;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceNumberingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceDeletionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_invoice_can_be_deleted_through_ajax_with_slot_items_pdf_and_audit_cleanup(): void
    {
        Storage::fake('local');
        [$order, $series, $invoice] = $this->issuedInvoice();
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        Storage::disk('local')->put($pdfPath, '%PDF-test');

        $response = $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ]);

        $response->assertOk()
            ->assertJsonPath('redirect_url', route('orders.show', $order))
            ->assertJsonStructure(['html', 'redirect_url']);
        $this->assertStringContainsString('WYSTAW FAKTUR', $response->json('html'));
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', ['invoice_id' => $invoice->getKey()]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'invoice_deleted',
            'title' => 'Usunięto fakturę',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'invoice_issued',
        ]);
        Storage::disk('local')->assertMissing($pdfPath);
        $this->assertSame(0, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
        $this->assertDatabaseHas('invoice_number_counter_adjustments', [
            'new_last_sequence_number' => 0,
            'reason' => 'Automatyczne cofnięcie wolnego końca numeracji po usunięciu Faktury VAT.',
        ]);
        $this->assertSame($series->getKey(), InvoiceNumberCounter::query()->firstOrFail()->invoice_series_id);
    }

    public function test_invoice_can_be_deleted_from_edit_page_with_standard_redirect(): void
    {
        [$order, , $invoice] = $this->issuedInvoice();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee(route('invoices.destroy', $invoice), false)
            ->assertSee('Usuń Fakturę');

        $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_deleted_from_list_returns_to_invoice_list(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
            'return_to' => 'invoices',
        ])->assertRedirect(route('invoices.index'))
            ->assertSessionHas('success', 'Faktura została usunięta.');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_stale_lock_version_does_not_delete_invoice(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version + 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_delete_conflict');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_without_order_returns_controlled_inconsistency(): void
    {
        [$order, , $invoice] = $this->issuedInvoice();
        $order->forceDelete();
        $invoice->refresh();

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_delete_inconsistent_document');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_with_correction_cannot_be_deleted(): void
    {
        [$order, , $invoice] = $this->issuedInvoice();
        $correctionSeries = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        Invoice::query()->create([
            'order_id' => $order->getKey(),
            'invoice_series_id' => $correctionSeries->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'corrected_invoice_id' => $invoice->getKey(),
        ]);

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_delete_blocked_by_correction');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_endpoint_rejects_proforma_and_inconsistent_slot(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $proforma = app(ProformaService::class)
            ->createOrRefresh($order, $proformaSeries, $this->documentContext())
            ->invoice;

        $this->deleteJson(route('invoices.destroy', $proforma), [
            'expected_lock_version' => $proforma->lock_version,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_delete_not_allowed');

        [, , $invoice] = $this->issuedInvoice();
        OrderDocumentSlot::query()
            ->where('invoice_id', $invoice->getKey())
            ->update(['invoice_id' => null]);

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_delete_inconsistent_document');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_deleting_internal_gap_does_not_reuse_number(): void
    {
        $series = $this->createDocumentSeries();
        $first = $this->issueForNewOrder($series);
        $middle = $this->issueForNewOrder($series);
        $last = $this->issueForNewOrder($series);

        app(InvoiceDeletionService::class)->delete(
            $middle,
            $middle->lock_version,
            $this->documentContext(),
        );

        $next = $this->issueForNewOrder($series);

        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(3, $last->sequence_number);
        $this->assertSame(4, $next->sequence_number);
    }

    public function test_deleting_free_tail_reuses_only_that_tail_number(): void
    {
        $series = $this->createDocumentSeries();
        $this->issueForNewOrder($series);
        $tail = $this->issueForNewOrder($series);

        app(InvoiceDeletionService::class)->delete(
            $tail,
            $tail->lock_version,
            $this->documentContext(),
        );

        $next = $this->issueForNewOrder($series);

        $this->assertSame(2, $next->sequence_number);
    }

    public function test_protected_floor_prevents_tail_counter_rollback(): void
    {
        $series = $this->createDocumentSeries();
        $invoice = $this->issueForNewOrder($series);
        InvoiceNumberCounter::query()->update(['protected_floor_sequence_number' => 1]);

        app(InvoiceDeletionService::class)->delete(
            $invoice,
            $invoice->lock_version,
            $this->documentContext(),
        );

        $this->assertSame(1, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_deleting_invoice_restores_exact_superseded_proforma_and_allows_it_to_be_refreshed(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proforma = app(ProformaService::class)
            ->createOrRefresh($order, $proformaSeries, $this->documentContext())
            ->invoice;
        $proformaNumber = $proforma->number;
        $proformaItems = $proforma->items()->get()->toArray();
        $invoice = app(InvoiceIssuingService::class)
            ->issue($order, $invoiceSeries, $this->documentContext('2026-07-28 13:00:00'));

        $response = $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ]);

        $response->assertOk();
        $this->assertFalse($proforma->refresh()->isProformaSuperseded());
        $this->assertNull($proforma->proforma_superseded_at);
        $this->assertNull($proforma->superseded_by_invoice_id);
        $this->assertSame($proformaNumber, $proforma->number);
        $this->assertSame($proformaItems, $proforma->items()->get()->toArray());
        $this->assertStringContainsString($proformaNumber, $response->json('html'));
        $html = app(OrderSalesDocumentActionsView::class)->render($order);
        $this->assertStringContainsString($proformaNumber, $html);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'proforma_restored',
            'title' => 'Przywrócono pro formę',
        ]);

        $refreshed = app(ProformaService::class)->createOrRefresh(
            $order->refresh(),
            $proformaSeries,
            $this->documentContext('2026-07-28 15:00:00'),
        )->invoice;
        $this->assertSame($proforma->getKey(), $refreshed->getKey());
        $this->assertSame($proformaNumber, $refreshed->number);

        $replacementInvoice = app(InvoiceIssuingService::class)
            ->issue($order->refresh(), $invoiceSeries, $this->documentContext('2026-07-28 16:00:00'));

        $this->assertTrue($proforma->refresh()->isProformaSuperseded());
        $this->assertSame($replacementInvoice->getKey(), $proforma->superseded_by_invoice_id);
    }

    public function test_failure_after_counter_release_rolls_back_entire_deletion(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proforma = app(ProformaService::class)
            ->createOrRefresh($order, $proformaSeries, $this->documentContext())
            ->invoice;
        $invoice = app(InvoiceIssuingService::class)
            ->issue($order, $invoiceSeries, $this->documentContext());
        $counter = InvoiceNumberCounter::query()
            ->where('invoice_series_id', $invoiceSeries->getKey())
            ->firstOrFail();
        $eventsBefore = $order->events()->count();
        $service = new class(app(InvoiceDeletionPolicy::class), app(InvoiceNumberingService::class), app(InvoicePdfStorage::class)) extends InvoiceDeletionService
        {
            protected function afterNumberReleased(Invoice $invoice): void
            {
                throw new DomainException('Wymuszony błąd po zwolnieniu numeru.');
            }
        };

        try {
            $service->delete($invoice, $invoice->lock_version, $this->documentContext());
            $this->fail('Usunięcie z wymuszonym błędem nie zostało wycofane.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_delete_numbering_inconsistent', $exception->errorCode());
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $invoice->getKey()]);
        $this->assertTrue($proforma->refresh()->isProformaSuperseded());
        $this->assertSame($invoice->getKey(), $proforma->superseded_by_invoice_id);
        $this->assertSame(1, $counter->refresh()->last_sequence_number);
        $this->assertSame($eventsBefore, $order->events()->count());
        $this->assertSame(0, $order->events()->where('event_type', 'proforma_restored')->count());
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    /** @return array{0: Order, 1: InvoiceSeries, 2: Invoice} */
    private function issuedInvoice(): array
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $invoice = app(InvoiceIssuingService::class)
            ->issue($order, $series, $this->documentContext());

        return [$order, $series, $invoice];
    }

    private function issueForNewOrder(InvoiceSeries $series): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)
            ->issue($order, $series, $this->documentContext());
    }
}
