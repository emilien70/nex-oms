<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceVatRateSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\AdditionalInformationRenderer;
use Modules\Invoices\Services\InvoiceDocumentPreparationService;
use Modules\Invoices\Services\ProformaSourceSnapshotHasher;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceDocumentPreparationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_stage_2c_schema_exists_without_soft_deletes_ksef_or_oss(): void
    {
        $this->assertTrue(Schema::hasColumns('order_document_slots', [
            'id', 'order_id', 'document_type', 'invoice_id', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('invoice_revisions', [
            'id', 'invoice_id', 'revision_number', 'document_snapshot', 'items_snapshot',
            'source_snapshot_hash', 'source', 'actor_snapshot', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('order_document_slots', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('invoice_revisions', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('invoice_revisions', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('invoices', 'proforma_superseded_at'));
        $this->assertTrue(Schema::hasColumn('invoices', 'superseded_by_invoice_id'));
        $this->assertFalse(Schema::hasColumn('invoices', 'ksef_number'));
        $this->assertFalse(Schema::hasTable('oss_settings'));
    }

    public function test_additional_information_replaces_every_token_and_preserves_other_text(): void
    {
        $order = $this->createDocumentOrder(['notes' => "ABC\nDEF"]);
        $series = $this->createDocumentSeries(attributes: [
            'additional_information_template' => "Początek\n[uwagi_sprzedawcy]\nPonownie: [uwagi_sprzedawcy]\nKoniec",
        ]);

        $result = app(AdditionalInformationRenderer::class)->render($series, $order);

        $this->assertSame("Początek\nABC\nDEF\nPonownie: ABC\nDEF\nKoniec", $result);
        $this->assertStringNotContainsString('[uwagi_sprzedawcy]', $result);

        $order->update(['notes' => null]);
        $this->assertStringNotContainsString(
            '[uwagi_sprzedawcy]',
            app(AdditionalInformationRenderer::class)->render($series, $order->refresh()),
        );
    }

    public function test_preparation_builds_snapshots_items_shipping_tax_totals_and_payment_without_float(): void
    {
        $order = $this->createDocumentOrder([
            'paid_amount' => '999.00',
            'currency' => 'eur',
            'shipping_country_code' => 'DE',
        ]);
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();

        $prepared = app(InvoiceDocumentPreparationService::class)
            ->forCreation($order, $series, $this->documentContext());

        $this->assertSame('NEX Seller sp. z o.o.', $prepared->invoiceAttributes['seller_snapshot']['name']);
        $this->assertSame('Kowalski Handel', $prepared->invoiceAttributes['buyer_snapshot']['company_name']);
        $this->assertSame('Anna Nowak', $prepared->invoiceAttributes['recipient_snapshot']['name']);
        $this->assertSame('PL', $prepared->invoiceAttributes['buyer_snapshot']['country_code']);
        $this->assertSame('Polska', $prepared->invoiceAttributes['buyer_snapshot']['country_name']);
        $this->assertSame('DE', $prepared->invoiceAttributes['recipient_snapshot']['country_code']);
        $this->assertSame('Niemcy', $prepared->invoiceAttributes['recipient_snapshot']['country_name']);
        $this->assertSame((string) $order->getKey(), $prepared->invoiceAttributes['order_reference_snapshot']);
        $this->assertSame('SHOP-100', $prepared->invoiceAttributes['order_snapshot']['external_id']);
        $this->assertSame('manual', $prepared->invoiceAttributes['order_snapshot']['source']);
        $this->assertSame('EUR', $prepared->invoiceAttributes['currency']);
        $this->assertCount(2, $prepared->itemAttributes);
        $this->assertSame(['product', 'shipping'], array_column($prepared->itemAttributes, 'line_type'));
        $this->assertSame('100.00', $prepared->itemAttributes[0]['total_gross']);
        $this->assertSame('Kurier testowy', $prepared->itemAttributes[1]['name']);
        $this->assertSame('100.00', $prepared->invoiceAttributes['total_net']);
        $this->assertSame('23.00', $prepared->invoiceAttributes['total_vat']);
        $this->assertSame('123.00', $prepared->invoiceAttributes['total_gross']);
        $this->assertSame('123.00', $prepared->invoiceAttributes['paid_amount']);
        $this->assertSame('0.00', $prepared->invoiceAttributes['amount_due']);
        $this->assertNull($prepared->invoiceAttributes['payment_due_date']);
        $this->assertSame('123.00', $prepared->invoiceAttributes['tax_summary_snapshot'][0]['gross']);
        $this->assertSnapshotContainsNoFloat($prepared->invoiceAttributes);
        $this->assertSnapshotContainsNoFloat($prepared->itemAttributes);
    }

    public function test_preparation_does_not_replace_empty_country_with_poland(): void
    {
        $order = $this->createDocumentOrder([
            'billing_country_code' => null,
            'shipping_country_code' => null,
        ]);
        $this->createDocumentItem($order);

        $prepared = app(InvoiceDocumentPreparationService::class)->forCreation(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertNull($prepared->invoiceAttributes['buyer_snapshot']['country_code']);
        $this->assertNull($prepared->invoiceAttributes['buyer_snapshot']['country_name']);
        $this->assertNull($prepared->invoiceAttributes['recipient_snapshot']['country_code']);
        $this->assertNull($prepared->invoiceAttributes['recipient_snapshot']['country_name']);
    }

    public function test_preparation_rejects_invalid_nonempty_country_with_controlled_error(): void
    {
        $order = $this->createDocumentOrder(['billing_country_code' => 'XX']);
        $this->createDocumentItem($order);

        try {
            app(InvoiceDocumentPreparationService::class)->forCreation(
                $order,
                $this->createDocumentSeries(),
                $this->documentContext(),
            );
            $this->fail('Nieprawidłowy kraj został zapisany w snapshocie dokumentu.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_country_invalid', $exception->errorCode());
            $this->assertStringContainsString('kraj danych do faktury', $exception->getMessage());
        }
    }

    public function test_missing_required_order_item_vat_is_not_replaced_with_23_percent(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order, ['vat_rate' => null]);
        $series = $this->createDocumentSeries(attributes: [
            'vat_rate_source' => InvoiceVatRateSource::OrderItem,
        ]);

        try {
            app(InvoiceDocumentPreparationService::class)
                ->forCreation($order, $series, $this->documentContext());
            $this->fail('Brakująca stawka VAT została zastąpiona wartością domyślną.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_tax_calculation_failed', $exception->errorCode());
        }
    }

    public function test_hash_is_canonical_for_associative_keys_but_preserves_list_order(): void
    {
        $hasher = app(ProformaSourceSnapshotHasher::class);

        $this->assertSame(
            $hasher->hash(['buyer' => ['name' => 'A', 'city' => 'B'], 'items' => [1, 2]]),
            $hasher->hash(['items' => [1, 2], 'buyer' => ['city' => 'B', 'name' => 'A']]),
        );
        $this->assertNotSame(
            $hasher->hash(['items' => [['name' => 'A'], ['name' => 'B']]]),
            $hasher->hash(['items' => [['name' => 'B'], ['name' => 'A']]]),
        );
    }

    private function assertSnapshotContainsNoFloat(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertSnapshotContainsNoFloat($item);
            }

            return;
        }

        $this->assertFalse(is_float($value), 'Snapshot zawiera wartość float.');
    }
}
