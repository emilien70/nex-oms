<?php

namespace Tests\Feature\Invoices;

use App\Models\Order;
use App\Models\OrderItem;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceSeriesManagementService;
use Tests\TestCase;

class InvoiceDocumentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_tables_contain_the_required_foundation_columns(): void
    {
        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTrue(Schema::hasColumns('invoices', [
            'id',
            'order_id',
            'invoice_series_id',
            'document_type',
            'status',
            'number',
            'sequence_number',
            'numbering_period_key',
            'number_format_snapshot',
            'series_name_snapshot',
            'issue_date',
            'sale_date',
            'payment_due_date',
            'issued_at',
            'lock_version',
            'source_snapshot_hash',
            'last_refreshed_at',
            'corrected_invoice_id',
            'previous_correction_id',
            'correction_reason',
            'correction_totals_snapshot',
            'order_reference_snapshot',
            'seller_name_snapshot',
            'seller_tax_id_snapshot',
            'buyer_name_snapshot',
            'buyer_tax_id_snapshot',
            'recipient_name_snapshot',
            'seller_snapshot',
            'buyer_snapshot',
            'recipient_snapshot',
            'issuer_snapshot',
            'order_snapshot',
            'payment_snapshot',
            'shipping_snapshot',
            'series_settings_snapshot',
            'tax_summary_snapshot',
            'tax_metadata_snapshot',
            'additional_information_text',
            'currency',
            'total_net',
            'total_vat',
            'total_gross',
            'paid_amount',
            'amount_due',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('invoice_items'));
        $this->assertTrue(Schema::hasColumns('invoice_items', [
            'id',
            'invoice_id',
            'order_item_id',
            'product_id',
            'source_invoice_item_id',
            'line_type',
            'position',
            'name',
            'description',
            'unit_name',
            'quantity',
            'unit_price_net',
            'unit_price_gross',
            'total_net',
            'total_vat',
            'total_gross',
            'vat_rate',
            'vat_code',
            'gtu_codes',
            'product_snapshot',
            'metadata',
            'correction_before_snapshot',
            'correction_after_snapshot',
            'correction_difference_snapshot',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_stage_does_not_add_out_of_scope_tables_or_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('invoices', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('orders', 'invoice_id'));
        $this->assertFalse(Schema::hasTable('products'));
        $this->assertFalse(Schema::hasTable('serial_numbers'));
        $this->assertFalse(Schema::hasTable('invoice_series_counters'));
        $this->assertFalse(Schema::hasTable('released_invoice_numbers'));
        $this->assertFalse(Schema::hasTable('proforma_revisions'));
        $this->assertFalse(Schema::hasTable('invoice_audits'));
        $this->assertFalse(Schema::hasTable('ksef_submissions'));
        $this->assertFalse(Schema::hasColumn('invoices', 'ksef_submission_mode'));
        $this->assertFalse(Schema::hasColumn('invoices', 'ksef_submission_mode_snapshot'));
        $this->assertFalse(Schema::hasColumn('invoice_series', 'ksef_submission_mode'));
    }

    public function test_product_id_is_nullable_and_has_no_foreign_key_to_a_products_table(): void
    {
        $invoice = $this->createInvoice();

        $item = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => 999999,
            'name' => 'Historyczna pozycja bez katalogu',
        ])->refresh();

        $this->assertSame(999999, $item->product_id);
        $this->assertFalse(collect(Schema::getForeignKeys('invoice_items'))->contains(
            fn (array $foreignKey): bool => in_array('product_id', $foreignKey['columns'], true)
        ));
    }

    public function test_document_and_item_enums_have_expected_values_and_polish_labels(): void
    {
        $this->assertSame(['draft', 'issued'], array_column(InvoiceDocumentStatus::cases(), 'value'));
        $this->assertSame('Szkic', InvoiceDocumentStatus::Draft->label());
        $this->assertSame('Wystawiony', InvoiceDocumentStatus::Issued->label());
        $this->assertSame(['product', 'shipping', 'custom'], array_column(InvoiceItemType::cases(), 'value'));
        $this->assertSame('Produkt', InvoiceItemType::Product->label());
        $this->assertSame('Dostawa', InvoiceItemType::Shipping->label());
        $this->assertSame('Pozycja własna', InvoiceItemType::Custom->label());
    }

    public function test_invoice_draft_uses_defaults_casts_and_does_not_generate_a_number(): void
    {
        $invoice = $this->createInvoice()->refresh();

        $this->assertSame(InvoiceDocumentType::Invoice, $invoice->document_type);
        $this->assertSame(InvoiceDocumentStatus::Draft, $invoice->status);
        $this->assertTrue($invoice->isDraft());
        $this->assertFalse($invoice->isIssued());
        $this->assertTrue($invoice->isInvoice());
        $this->assertFalse($invoice->isProforma());
        $this->assertFalse($invoice->isCorrection());
        $this->assertNull($invoice->number);
        $this->assertNull($invoice->sequence_number);
        $this->assertNull($invoice->numbering_period_key);
        $this->assertSame(1, $invoice->lock_version);
        $this->assertNull($invoice->source_snapshot_hash);
        $this->assertSame('PLN', $invoice->currency);
        $this->assertSame('0.00', $invoice->total_gross);
        $this->assertFalse(Schema::hasTable('invoice_series_counters'));
    }

    public function test_proforma_and_correction_drafts_can_be_stored(): void
    {
        $source = $this->createInvoice();
        $proforma = $this->createInvoice(InvoiceDocumentType::Proforma);
        $correction = $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $source->id,
        ]);

        $this->assertTrue($proforma->isProforma());
        $this->assertTrue($proforma->isDraft());
        $this->assertTrue($correction->isCorrection());
        $this->assertTrue($correction->correctedInvoice->is($source));
        $this->assertNull($correction->previous_correction_id);
    }

    public function test_json_dates_lock_version_and_decimal_values_are_cast_correctly(): void
    {
        $invoice = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'status' => InvoiceDocumentStatus::Issued,
            'issue_date' => '2026-07-28',
            'sale_date' => '2026-07-27',
            'payment_due_date' => '2026-08-11',
            'issued_at' => '2026-07-28 12:30:00',
            'last_refreshed_at' => '2026-07-28 13:00:00',
            'sequence_number' => 15,
            'lock_version' => 3,
            'seller_snapshot' => ['name' => 'NEX'],
            'buyer_snapshot' => ['city' => 'Warszawa'],
            'recipient_snapshot' => ['name' => 'Odbiorca'],
            'issuer_snapshot' => ['place' => 'Warszawa'],
            'order_snapshot' => ['id' => 10],
            'payment_snapshot' => ['method' => 'Przelew'],
            'shipping_snapshot' => ['gross' => '10.00'],
            'series_settings_snapshot' => ['reset_period' => 'yearly'],
            'tax_summary_snapshot' => ['23' => ['gross' => '123.00']],
            'tax_metadata_snapshot' => ['gtu' => ['GTU_06']],
            'correction_totals_snapshot' => ['difference' => ['gross' => '-10.00']],
            'total_net' => '100.01',
            'total_vat' => '23.00',
            'total_gross' => '123.01',
            'paid_amount' => '50.00',
            'amount_due' => '73.01',
        ])->refresh();

        $this->assertTrue($invoice->isIssued());
        $this->assertInstanceOf(Carbon::class, $invoice->issue_date);
        $this->assertInstanceOf(Carbon::class, $invoice->sale_date);
        $this->assertInstanceOf(Carbon::class, $invoice->payment_due_date);
        $this->assertInstanceOf(Carbon::class, $invoice->issued_at);
        $this->assertInstanceOf(Carbon::class, $invoice->last_refreshed_at);
        $this->assertSame(15, $invoice->sequence_number);
        $this->assertSame(3, $invoice->lock_version);
        $this->assertSame(['name' => 'NEX'], $invoice->seller_snapshot);
        $this->assertSame(['city' => 'Warszawa'], $invoice->buyer_snapshot);
        $this->assertSame(['method' => 'Przelew'], $invoice->payment_snapshot);
        $this->assertSame(['difference' => ['gross' => '-10.00']], $invoice->correction_totals_snapshot);
        $this->assertSame('100.01', $invoice->total_net);
        $this->assertSame('23.00', $invoice->total_vat);
        $this->assertSame('123.01', $invoice->total_gross);
        $this->assertSame('50.00', $invoice->paid_amount);
        $this->assertSame('73.01', $invoice->amount_due);
    }

    public function test_buyer_snapshot_contains_editable_data_without_following_order_changes(): void
    {
        $order = $this->createOrder(['billing_city' => 'Warszawa']);
        $buyerSnapshot = [
            'name' => 'Anna Kowalska',
            'company' => 'Kowalska Handel',
            'street' => 'Testowa',
            'building_number' => '12',
            'apartment_number' => '6',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'province' => 'mazowieckie',
            'country_code' => 'PL',
            'tax_id' => '1234567890',
            'email' => 'anna@example.com',
            'phone' => '+48 501 234 567',
        ];
        $invoice = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'order_id' => $order->id,
            'buyer_snapshot' => $buyerSnapshot,
        ]);

        $order->update(['billing_city' => 'Kraków']);

        $this->assertSame($buyerSnapshot, $invoice->refresh()->buyer_snapshot);
    }

    public function test_seller_issuer_payment_and_information_snapshots_are_independent(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $invoice = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'invoice_series_id' => $series->id,
            'seller_snapshot' => ['name' => 'Sprzedawca historyczny'],
            'issuer_snapshot' => ['place_of_issue' => 'Psary', 'issuer_name' => 'Jan Kowalski'],
            'payment_snapshot' => ['method' => 'Przelew', 'due_date' => '2026-08-10'],
            'additional_information_text' => "Numery:\nABC123",
        ]);

        $series->update([
            'seller_name' => 'Nowa nazwa sprzedawcy',
            'additional_information_template' => 'Nowy szablon',
        ]);

        $invoice->refresh();
        $this->assertSame(['name' => 'Sprzedawca historyczny'], $invoice->seller_snapshot);
        $this->assertSame('Psary', $invoice->issuer_snapshot['place_of_issue']);
        $this->assertSame('Przelew', $invoice->payment_snapshot['method']);
        $this->assertSame("Numery:\nABC123", $invoice->additional_information_text);
    }

    public function test_order_series_and_items_relations_work_and_items_are_ordered(): void
    {
        $order = $this->createOrder();
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $invoice = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'order_id' => $order->id,
            'invoice_series_id' => $series->id,
        ]);
        $later = $this->createInvoiceItem($invoice, ['name' => 'Druga', 'position' => 2]);
        $first = $this->createInvoiceItem($invoice, ['name' => 'Pierwsza', 'position' => 1]);

        $this->assertTrue($invoice->order->is($order));
        $this->assertTrue($order->invoices->contains($invoice));
        $this->assertTrue($invoice->series->is($series));
        $this->assertTrue($series->invoices->contains($invoice));
        $this->assertSame([$first->id, $later->id], $invoice->items->pluck('id')->all());
        $this->assertTrue($first->invoice->is($invoice));
    }

    public function test_invoice_item_supports_all_types_fractional_values_tax_and_snapshots(): void
    {
        $invoice = $this->createInvoice();
        $product = $this->createInvoiceItem($invoice, [
            'line_type' => InvoiceItemType::Product,
            'quantity' => '1.2500',
            'unit_price_net' => '10.1234',
            'unit_price_gross' => '12.4500',
            'total_net' => '12.65',
            'total_vat' => '2.91',
            'total_gross' => '15.56',
            'vat_rate' => '23.00',
            'vat_code' => 'zw',
            'gtu_codes' => ['GTU_06'],
            'product_snapshot' => ['internal_id' => 15],
            'metadata' => ['source' => 'order'],
        ])->refresh();
        $shipping = $this->createInvoiceItem($invoice, [
            'line_type' => InvoiceItemType::Shipping,
            'name' => 'Dostawa',
        ]);
        $custom = $this->createInvoiceItem($invoice, [
            'line_type' => InvoiceItemType::Custom,
            'name' => 'Pozycja własna',
        ]);

        $this->assertSame(InvoiceItemType::Product, $product->line_type);
        $this->assertSame(InvoiceItemType::Shipping, $shipping->line_type);
        $this->assertSame(InvoiceItemType::Custom, $custom->line_type);
        $this->assertSame('1.2500', $product->quantity);
        $this->assertSame('10.1234', $product->unit_price_net);
        $this->assertSame('12.4500', $product->unit_price_gross);
        $this->assertSame('12.65', $product->total_net);
        $this->assertSame('2.91', $product->total_vat);
        $this->assertSame('15.56', $product->total_gross);
        $this->assertSame('23.00', $product->vat_rate);
        $this->assertSame('zw', $product->vat_code);
        $this->assertSame(['GTU_06'], $product->gtu_codes);
        $this->assertSame(['internal_id' => 15], $product->product_snapshot);
        $this->assertSame(['source' => 'order'], $product->metadata);
    }

    public function test_order_item_relations_and_snapshot_independence_work(): void
    {
        $order = $this->createOrder();
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Produkt źródłowy',
            'quantity' => 1,
            'unit_price_gross' => '100.00',
            'total_price_gross' => '100.00',
        ]);
        $invoiceItem = $this->createInvoiceItem($this->createInvoice(), [
            'order_item_id' => $orderItem->id,
            'name' => 'Produkt źródłowy',
            'unit_price_gross' => '100.0000',
            'product_snapshot' => ['name' => 'Produkt źródłowy'],
        ]);

        $orderItem->update(['product_name' => 'Produkt zmieniony', 'unit_price_gross' => '50.00']);

        $invoiceItem->refresh();
        $this->assertTrue($invoiceItem->orderItem->is($orderItem));
        $this->assertTrue($orderItem->invoiceItems->contains($invoiceItem));
        $this->assertSame('Produkt źródłowy', $invoiceItem->name);
        $this->assertSame('100.0000', $invoiceItem->unit_price_gross);
        $this->assertSame(['name' => 'Produkt źródłowy'], $invoiceItem->product_snapshot);
    }

    public function test_correction_item_snapshots_and_source_relations_work(): void
    {
        $sourceInvoice = $this->createInvoice();
        $sourceItem = $this->createInvoiceItem($sourceInvoice);
        $correction = $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $sourceInvoice->id,
            'correction_totals_snapshot' => [
                'before' => ['gross' => '100.00'],
                'after' => ['gross' => '80.00'],
                'difference' => ['gross' => '-20.00'],
            ],
        ]);
        $correctionItem = $this->createInvoiceItem($correction, [
            'source_invoice_item_id' => $sourceItem->id,
            'correction_before_snapshot' => ['quantity' => '1.0000'],
            'correction_after_snapshot' => ['quantity' => '0.8000'],
            'correction_difference_snapshot' => ['quantity' => '-0.2000'],
        ])->refresh();

        $this->assertTrue($correctionItem->sourceInvoiceItem->is($sourceItem));
        $this->assertTrue($sourceItem->correctionItems->contains($correctionItem));
        $this->assertSame(['quantity' => '1.0000'], $correctionItem->correction_before_snapshot);
        $this->assertSame(['quantity' => '0.8000'], $correctionItem->correction_after_snapshot);
        $this->assertSame(['quantity' => '-0.2000'], $correctionItem->correction_difference_snapshot);
        $this->assertSame('-20.00', $correction->correction_totals_snapshot['difference']['gross']);
    }

    public function test_invoice_supports_a_linear_chain_of_multiple_corrections(): void
    {
        $invoice = $this->createInvoice(InvoiceDocumentType::Invoice, ['total_gross' => '100.00']);
        $first = $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $invoice->id,
            'correction_reason' => 'Pierwsza korekta',
        ]);
        $second = $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $invoice->id,
            'previous_correction_id' => $first->id,
        ]);
        $third = $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $invoice->id,
            'previous_correction_id' => $second->id,
        ]);

        $this->assertSame([$first->id, $second->id, $third->id], $invoice->corrections()->orderBy('id')->pluck('id')->all());
        $this->assertTrue($second->previousCorrection->is($first));
        $this->assertTrue($third->previousCorrection->is($second));
        $this->assertTrue($first->nextCorrections->contains($second));
        $this->assertTrue($second->nextCorrections->contains($third));
        $this->assertSame('100.00', $invoice->refresh()->total_gross);
    }

    public function test_previous_correction_cannot_have_two_next_corrections(): void
    {
        $invoice = $this->createInvoice();
        $first = $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $invoice->id,
        ]);
        $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $invoice->id,
            'previous_correction_id' => $first->id,
        ]);

        $this->expectException(QueryException::class);

        $this->createInvoice(InvoiceDocumentType::Correction, [
            'corrected_invoice_id' => $invoice->id,
            'previous_correction_id' => $first->id,
        ]);
    }

    public function test_many_drafts_in_one_series_can_have_a_null_number(): void
    {
        $first = $this->createInvoice();
        $second = $this->createInvoice();

        $this->assertNull($first->number);
        $this->assertNull($second->number);
        $this->assertSame($first->invoice_series_id, $second->invoice_series_id);
    }

    public function test_non_null_document_number_is_unique_inside_a_series(): void
    {
        $this->createInvoice(InvoiceDocumentType::Invoice, ['number' => 'FV 1/2026']);

        $this->expectException(QueryException::class);

        $this->createInvoice(InvoiceDocumentType::Invoice, ['number' => 'FV 1/2026']);
    }

    public function test_the_same_document_number_can_exist_in_different_series(): void
    {
        $firstSeries = $this->createCustomSeries('Pierwsza seria');
        $secondSeries = $this->createCustomSeries('Druga seria');

        $first = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'invoice_series_id' => $firstSeries->id,
            'number' => 'FV 1/2026',
        ]);
        $second = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'invoice_series_id' => $secondSeries->id,
            'number' => 'FV 1/2026',
        ]);

        $this->assertNotSame($first->invoice_series_id, $second->invoice_series_id);
        $this->assertSame($first->number, $second->number);
    }

    public function test_deleting_an_order_keeps_the_document_and_nulls_the_relation(): void
    {
        $order = $this->createOrder();
        $invoice = $this->createInvoice(InvoiceDocumentType::Invoice, [
            'order_id' => $order->id,
            'order_snapshot' => ['id' => $order->id, 'source' => 'manual'],
        ]);

        $order->forceDelete();

        $invoice->refresh();
        $this->assertNull($invoice->order_id);
        $this->assertNull($invoice->order);
        $this->assertSame(['id' => $order->id, 'source' => 'manual'], $invoice->order_snapshot);
    }

    public function test_deleting_an_order_item_keeps_the_document_item_and_nulls_the_relation(): void
    {
        $order = $this->createOrder();
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'total_price_gross' => '10.00',
        ]);
        $invoiceItem = $this->createInvoiceItem($this->createInvoice(), [
            'order_item_id' => $orderItem->id,
            'name' => 'Snapshot produktu',
        ]);

        $orderItem->delete();

        $invoiceItem->refresh();
        $this->assertNull($invoiceItem->order_item_id);
        $this->assertSame('Snapshot produktu', $invoiceItem->name);
    }

    public function test_deleting_a_document_cascades_to_its_items(): void
    {
        $invoice = $this->createInvoice();
        $item = $this->createInvoiceItem($invoice);

        $invoice->delete();

        $this->assertDatabaseMissing('invoice_items', ['id' => $item->id]);
    }

    public function test_series_used_by_each_document_type_cannot_be_deleted(): void
    {
        $service = app(InvoiceSeriesManagementService::class);

        foreach (InvoiceDocumentType::cases() as $index => $type) {
            $series = $this->createCustomSeries('Seria użyta '.$index, $type, false);
            $this->createInvoice($type, ['invoice_series_id' => $series->id]);

            try {
                $service->delete($series);
                $this->fail('Użyta seria została usunięta.');
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Nie można usunąć serii numeracji, ponieważ została użyta w dokumentach. Serię można ukryć i później ponownie aktywować.',
                    $exception->getMessage(),
                );
            }

            $this->assertDatabaseHas('invoice_series', ['id' => $series->id]);
        }
    }

    public function test_used_series_can_be_hidden_and_reactivated_without_changing_its_identity(): void
    {
        $service = app(InvoiceSeriesManagementService::class);
        $series = $this->createCustomSeries('Seria do archiwizacji', InvoiceDocumentType::Invoice, true);
        $this->createInvoice(InvoiceDocumentType::Invoice, ['invoice_series_id' => $series->id]);
        $originalId = $series->id;

        $service->setActive($series, false);
        $this->assertFalse($series->refresh()->is_active);
        $service->setActive($series, true);

        $this->assertTrue($series->refresh()->is_active);
        $this->assertSame($originalId, $series->id);
    }

    public function test_unused_inactive_custom_series_can_still_be_deleted(): void
    {
        $service = app(InvoiceSeriesManagementService::class);
        $series = $this->createCustomSeries('Seria nieużywana');

        $service->delete($series);

        $this->assertDatabaseMissing('invoice_series', ['id' => $series->id]);
    }

    public function test_existing_series_protections_still_apply(): void
    {
        $service = app(InvoiceSeriesManagementService::class);
        $active = $this->createCustomSeries('Aktywna seria', InvoiceDocumentType::Invoice, true);
        $system = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);

        try {
            $service->delete($active);
            $this->fail('Aktywna seria została usunięta.');
        } catch (DomainException $exception) {
            $this->assertSame('Nie można usunąć aktywnej serii numeracji. Najpierw ją ukryj.', $exception->getMessage());
        }

        try {
            $service->setActive($system, false);
            $this->fail('Seria systemowa została ukryta.');
        } catch (DomainException $exception) {
            $this->assertSame('Seria systemowa jest zawsze aktywna i nie może zostać ukryta.', $exception->getMessage());
        }

        try {
            $service->delete($system);
            $this->fail('Seria systemowa została usunięta.');
        } catch (DomainException $exception) {
            $this->assertSame('Predefiniowanej serii systemowej nie można usunąć.', $exception->getMessage());
        }
    }

    public function test_stage_adds_only_explicit_invoice_editing_routes(): void
    {
        $this->assertTrue(Route::has('invoices.edit'));
        $this->assertTrue(Route::has('invoices.buyer.update'));
        $this->assertTrue(Route::has('invoices.recipient.update'));
        $this->assertTrue(Route::has('invoices.details.update'));
        $this->assertTrue(Route::has('invoices.items.store'));
        $this->assertTrue(Route::has('invoices.items.update'));
        $this->assertTrue(Route::has('invoices.items.destroy'));
        $this->assertTrue(Route::has('invoices.items.copy-from-order'));
        $this->assertFalse(Route::has('invoices.update'));
        $this->assertFalse(Route::has('invoices.issue'));
        $this->assertFalse(Route::has('invoices.destroy'));
        $this->assertFileExists(base_path('Modules/Invoices/Http/Controllers/InvoiceEditController.php'));
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
        ], $attributes));
    }

    private function createInvoice(
        InvoiceDocumentType $type = InvoiceDocumentType::Invoice,
        array $attributes = [],
    ): Invoice {
        $seriesKey = match ($type) {
            InvoiceDocumentType::Invoice => InvoiceSeriesSystemKey::Invoice,
            InvoiceDocumentType::Proforma => InvoiceSeriesSystemKey::Proforma,
            InvoiceDocumentType::Correction => InvoiceSeriesSystemKey::Correction,
        };

        return Invoice::query()->create(array_merge([
            'invoice_series_id' => $this->systemSeries($seriesKey)->id,
            'document_type' => $type,
        ], $attributes))->refresh();
    }

    private function createInvoiceItem(Invoice $invoice, array $attributes = []): InvoiceItem
    {
        return InvoiceItem::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'line_type' => InvoiceItemType::Product,
            'position' => 1,
            'name' => 'Pozycja dokumentu',
        ], $attributes));
    }

    private function createCustomSeries(
        string $name,
        InvoiceDocumentType $type = InvoiceDocumentType::Invoice,
        bool $active = false,
    ): InvoiceSeries {
        return InvoiceSeries::query()->create([
            'document_type' => $type,
            'name' => $name,
            'number_format' => strtoupper(substr(md5($name), 0, 6)).'/%N/%Y',
            'is_active' => $active,
        ])->refresh();
    }

    private function systemSeries(InvoiceSeriesSystemKey $key): InvoiceSeries
    {
        return InvoiceSeries::query()->where('system_key', $key->value)->firstOrFail();
    }
}
