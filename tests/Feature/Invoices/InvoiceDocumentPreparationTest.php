<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;
use Modules\Invoices\Enums\InvoiceVatRateSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\AdditionalInformationRenderer;
use Modules\Invoices\Services\InvoiceDocumentPreparationService;
use Modules\Invoices\Services\InvoiceIssuingService;
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
        $this->assertFalse(Schema::hasTable('invoice_revisions'));
        $this->assertFalse(Schema::hasColumn('order_document_slots', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('invoices', 'lock_version'));
        $this->assertFalse(Schema::hasColumn('invoices', 'revision_number'));
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
            'payment_status' => 'paid',
            'paid_amount' => '123.00',
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

    public function test_prestashop_invoice_uses_provider_transaction_as_payment_identifier(): void
    {
        $order = $this->createDocumentOrder([
            'source' => 'prestashop',
            'external_id' => '13592',
            'payment_status' => 'paid',
            'paid_amount' => '123.00',
        ]);
        $this->createDocumentItem($order);
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
        ]);
        $link = $account->orderLinks()->create([
            'order_id' => $order->getKey(),
            'external_order_id' => 13592,
            'external_reference' => '13592',
            'source_hash' => hash('sha256', 'prestashop-order-13592'),
            'imported_at' => now(),
        ]);
        $link->payments()->create([
            'external_payment_id' => 9129,
            'transaction_id' => 'PRHS-PBD-I44-JVG',
            'amount' => '123.00',
            'currency' => 'PLN',
            'method' => 'PayNow',
            'paid_at' => now(),
            'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
            'accounted_at' => now(),
        ]);

        $prepared = app(InvoiceDocumentPreparationService::class)
            ->forCreation($order, $this->createDocumentSeries(), $this->documentContext());

        $this->assertSame($order->getKey(), $prepared->invoiceAttributes['order_snapshot']['id']);
        $this->assertSame('13592', $prepared->invoiceAttributes['order_snapshot']['external_id']);
        $this->assertSame(
            'PRHS-PBD-I44-JVG',
            $prepared->invoiceAttributes['payment_snapshot']['payment_identifier'],
        );
        $this->assertSame(
            'provider_transaction',
            $prepared->invoiceAttributes['payment_snapshot']['payment_identifier_source'],
        );
    }

    public function test_historical_prestashop_invoice_payment_identifier_is_repaired_reversibly(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder([
            'source' => 'prestashop',
            'external_id' => '13592',
            'payment_status' => 'paid',
            'paid_amount' => '123.00',
        ]);
        $this->createDocumentItem($order);
        $account = PrestashopAccount::query()->create([
            'account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'multi-click.pl',
            'base_url' => 'https://multi-click.pl',
            'api_key' => '12345678901234567890123456789012',
            'is_active' => true,
        ]);
        $link = $account->orderLinks()->create([
            'order_id' => $order->getKey(),
            'external_order_id' => 13592,
            'external_reference' => '13592',
            'source_hash' => hash('sha256', 'prestashop-order-13592'),
            'imported_at' => '2026-07-20 10:00:00',
        ]);
        $link->payments()->create([
            'external_payment_id' => 9129,
            'transaction_id' => 'PRHS-PBD-I44-JVG',
            'amount' => '123.00',
            'currency' => 'PLN',
            'method' => 'PayNow',
            'paid_at' => '2026-07-21 11:00:00',
            'accounting_status' => PrestashopOrderPayment::STATUS_ACCOUNTED,
            'accounted_at' => '2026-07-21 11:00:00',
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $legacyPayment = $invoice->payment_snapshot;
        $legacyPayment['payment_identifier'] = '13592';
        unset($legacyPayment['payment_identifier_source']);
        $invoice->update(['payment_snapshot' => $legacyPayment]);
        $cachePath = 'invoices/'.$invoice->getKey().'/invoice-v47-ksef-demo.pdf';
        $attachmentPath = 'invoices/'.$invoice->getKey().'/external-document.pdf';
        Storage::disk('local')->put($cachePath, '%PDF-cache');
        Storage::disk('local')->put($attachmentPath, '%PDF-attachment');

        $migration = require database_path(
            'migrations/2026_10_15_020000_correct_invoice_order_and_payment_identifiers.php',
        );
        $migration->up();
        $cacheMigration = require database_path(
            'migrations/2026_10_15_021000_invalidate_corrected_invoice_pdf_cache.php',
        );
        $cacheMigration->up();

        $invoice->refresh();
        $this->assertSame('PRHS-PBD-I44-JVG', $invoice->payment_snapshot['payment_identifier']);
        $this->assertSame(
            'provider_transaction_repair_v1',
            $invoice->payment_snapshot['payment_identifier_source'],
        );
        Storage::disk('local')->assertMissing($cachePath);
        Storage::disk('local')->assertExists($attachmentPath);

        $migration->down();

        $invoice->refresh();
        $this->assertSame('13592', $invoice->payment_snapshot['payment_identifier']);
        $this->assertArrayNotHasKey('payment_identifier_source', $invoice->payment_snapshot);
    }

    public function test_preparation_rejects_missing_order_currency_without_inventing_pln(): void
    {
        $order = $this->createDocumentOrder(['currency' => '']);
        $this->createDocumentItem($order, ['currency' => '']);

        try {
            app(InvoiceDocumentPreparationService::class)->forCreation(
                $order,
                $this->createDocumentSeries(),
                $this->documentContext(),
            );
            $this->fail('Brak waluty zamówienia został zastąpiony domyślnym kodem.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_currency_missing', $exception->errorCode());
            $this->assertStringContainsString('nie ma ustawionej waluty', $exception->getMessage());
        }
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
